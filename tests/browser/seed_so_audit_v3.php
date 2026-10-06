<?php
declare(strict_types=1);

/**
 * Seed for the Laporan Stock Opname v3 browser test (playwright_so_audit_v3.mjs): the same Jejak fixture as seed_so_audit.php (one LEGACY_DUAL_COUNT and one
 * FINDINGS_V1 POSTED session with real evidence photos, a voided finding, real posted adjustments — all built through the application's own services) PLUS
 * 12 header-only sessions (6 OPEN, 6 CANCELLED, September 2026, no lines) so the Ringkasan Sesi pagination / status filter / empty-session rendering can be tested.
 * Test database only. Prints one JSON document.
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

$ins = $pdo->prepare(
    "INSERT INTO stock_opname_sessions (warehouse_id, session_date, session_uuid, session_number, scope, status, counting_model, created_by, cancelled_by, cancelled_at, created_at)
     VALUES (:wh, :d, UUID(), :no, 'ALL_ACTIVE_STOCK', :st, 'LEGACY_DUAL_COUNT', :u, :cb, :ca, :cr)"
);
$extra = [];
for ($i = 1; $i <= 12; $i++) {
    $cancelled = $i % 2 === 0;
    $d = sprintf('2026-09-%02d', $i);
    $no = sprintf('SO-%s-%04d', str_replace('-', '', $d), 100 + $i);
    $ins->execute([
        'wh' => $fx['legacy']['warehouse_id'] + ($i % 2), 'd' => $d, 'no' => $no, 'st' => $cancelled ? 'CANCELLED' : 'OPEN', 'u' => $fx['admin']['id'],
        'cb' => $cancelled ? $fx['admin']['id'] : null, 'ca' => $cancelled ? "{$d} 17:00:00" : null, 'cr' => "{$d} 08:00:00",
    ]);
    $extra[] = ['session_number' => $no, 'status' => $cancelled ? 'CANCELLED' : 'OPEN', 'date' => $d];
}
echo json_encode([
    'admin' => $fx['admin'], 'viewer' => $fx['viewer'], 'outsider' => $fx['outsider'],
    'legacy' => $mk($fx['legacy']), 'findings' => $mk($fx['findings']), 'extra' => $extra,
]);
