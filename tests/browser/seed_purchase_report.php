<?php
declare(strict_types=1);

/** Seed for the Laporan Pembelian browser test: real Stock IN V2 purchases (tests/lib/purchase_report_fixture.php). Prints one JSON document. */
require_once __DIR__ . '/../lib/purchase_report_fixture.php';

use App\Services\Database;

$pdo = Database::connection();
$fx = purchase_build_fixture($pdo);
$items = [];
foreach ($fx['items'] as $k => $i) {
    $items[$k] = ['id' => $i['id'], 'sku' => $i['sku'], 'name' => $i['name']];
}
echo json_encode(['admin' => $fx['admin'], 'viewer' => $fx['viewer'], 'stock2' => $fx['stock2'], 'wh' => $fx['wh'], 'sup' => $fx['sup'], 'cat' => $fx['cat'], 'items' => $items, 'tx' => $fx['tx'], 'range' => $fx['range'], 'expect' => $fx['expect']]);
