<?php
declare(strict_types=1);

/**
 * MOCKUP SMOKE TEST SEED — Jejak Stock Opname drawer.
 * One admin + one minimal POSTED stock_opname_sessions row, just enough
 * for Laporan Stock Opname's session list to render a clickable row.
 * The drawer's own content (Per Barang table, KPIs) is 100% mock data
 * from stock-opname-report-jejak.js — this seed does not need real
 * stock_opname_lines/findings data for that.
 */

require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/Exceptions.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/NumberingService.php';

use App\Services\Database;

function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(3)); }

$pdo = Database::connection();

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();

$pass = 'StbJkTest' . bin2hex(random_bytes(4)) . '!1';
$username = uid('stbjk-admin');
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => $username, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $username, 'r' => $superRoleId]);
$adminId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES (:c, 'Gudang Cibadak Mock', 'TRANSIT', 1)")->execute(['c' => uid('STBJK-WH')]);
$whId = (int) $pdo->lastInsertId();

$sessionUuid = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
$pdo->prepare(
    'INSERT INTO stock_opname_sessions
        (warehouse_id, session_date, session_uuid, session_number, scope, status, counting_model,
         created_by, finalized_by, finalized_at, posted_by, posted_at)
     VALUES (:wh, :date, :uuid, :num, \'ALL_ACTIVE_STOCK\', \'POSTED\', \'FINDINGS_V1\',
             :created_by, :finalized_by, :finalized_at, :posted_by, :posted_at)'
)->execute([
    'wh' => $whId, 'date' => '2026-09-30', 'uuid' => $sessionUuid, 'num' => 'SO-20260930-0012',
    'created_by' => $adminId, 'finalized_by' => $adminId, 'finalized_at' => '2026-10-01 10:15:00',
    'posted_by' => $adminId, 'posted_at' => '2026-10-01 11:30:00',
]);
$sessionId = (int) $pdo->lastInsertId();

echo json_encode([
    'admin' => ['username' => $username, 'password' => $pass],
    'session_id' => $sessionId,
    'session_number' => 'SO-20260930-0012',
]);
