<?php
declare(strict_types=1);

/**
 * Seed for the real-data Dashboard browser test: the 3-warehouse fixture
 * (tests/lib/dashboard_fixture.php, built through the real services) plus
 *   - 70 bulk SKUs opened in warehouse A (forces >50 rows => pagination), and
 *   - one STRESS SKU with a 15-digit Rupiah value (proves no card overflow).
 * Prints one JSON document.
 */
require_once __DIR__ . '/../lib/dashboard_fixture.php';

use App\Services\Database;

$pdo = Database::connection();
$fx = dashboard_build_fixture($pdo);
$by = $fx['admin']['id'];
$kg = $fx['kg'];
$A = $fx['wh']['A'];
$D = df_wh($pdo, 'Gudang Kosong (fixture)'); // no transactions at all: empty-state case

for ($i = 1; $i <= 70; $i++) {
    $it = df_item($pdo, $kg, 'DFBULK', $fx['cat']['bahan'], 0);
    df_in($it['id'], $A, 10 + $i, 1000 + $i, '2026-05-20 08:00:00', $by, $kg, 'IN', 'PO-BULK');
}
$stress = df_item($pdo, $kg, 'DFSTRESS', $fx['cat']['roti'], 0);
df_in($stress['id'], $A, 900000, 95000000, '2026-05-21 08:00:00', $by, $kg, 'IN', 'PO-STRESS');

echo json_encode([
    'admin' => $fx['admin'], 'viewer' => $fx['viewer'], 'stockA' => $fx['stockA'],
    'wh' => $fx['wh'] + ['D' => $D], 'cat' => $fx['cat'], 'range' => $fx['range'], 'today' => $fx['today'],
    'stressSku' => $stress['sku'], 'tepungSku' => $fx['items']['tepung']['sku'],
]);
