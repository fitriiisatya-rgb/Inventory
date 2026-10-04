<?php
declare(strict_types=1);

/**
 * Seed for the Pergerakan Stok Harian browser test: the 3-warehouse ledger fixture (tests/lib/dashboard_fixture.php, built through the real
 * posting services: purchases, OUTs, received + in-transit transfers, adjustments, a VOIDED purchase, a mid-period OPENING) plus items in
 * PCS and LTR (so quantities of different units exist) and activity on the 10 days before today. Prints one JSON document.
 */
require_once __DIR__ . '/../lib/dashboard_fixture.php';

use App\Services\Database;

$pdo = Database::connection();
$fx = dashboard_build_fixture($pdo);
$by = $fx['admin']['id'];
$kg = $fx['kg'];
$pcs = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
$ltr = (int) $pdo->query("SELECT id FROM units WHERE code='LTR'")->fetchColumn();
$p1 = df_item($pdo, $pcs, 'MVPCS', $fx['cat']['roti'], 0);
$l1 = df_item($pdo, $ltr, 'MVLTR', $fx['cat']['bahan'], 0);
$A = $fx['wh']['A'];
$d = static fn (int $back, string $t = '09:30:00') => date('Y-m-d', strtotime("-{$back} days")) . ' ' . $t;
df_in($p1['id'], $A, 120, 500, $d(9), $by, $pcs, 'IN', 'PO-PCS');
df_in($l1['id'], $A, 32, 9000, $d(9, '09:45:00'), $by, $ltr, 'IN', 'PO-LTR');
df_out($p1['id'], $A, 20, $d(6, '10:00:00'), $by, $pcs, 'OUT-PCS');
df_in($p1['id'], $A, 60, 520, $d(4, '11:00:00'), $by, $pcs, 'IN', 'PO-PCS-2');
df_out($l1['id'], $A, 5, $d(3, '08:00:00'), $by, $ltr, 'OUT-LTR');
echo json_encode([
    'admin' => $fx['admin'], 'viewer' => $fx['viewer'], 'stockA' => $fx['stockA'],
    'wh' => $fx['wh'], 'cat' => $fx['cat'], 'items' => ['pcs' => $p1, 'ltr' => $l1] + $fx['items'], 'today' => $fx['today'],
    'range' => $fx['range'],
]);
