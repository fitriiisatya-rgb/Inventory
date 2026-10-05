<?php
declare(strict_types=1);

/** Seed for the Laporan Nilai Stok & HPP browser test: real FIFO postings (tests/lib/valuation_fixture.php). Prints one JSON document. */
require_once __DIR__ . '/../lib/valuation_fixture.php';

use App\Services\Database;

$pdo = Database::connection();
$fx = valuation_build_fixture($pdo);
$items = [];
foreach ($fx['items'] as $k => $i) {
    $items[$k] = ['id' => $i['id'], 'sku' => $i['sku'], 'name' => $i['name']];
}
echo json_encode(['admin' => $fx['admin'], 'viewer' => $fx['viewer'], 'stock2' => $fx['stock2'], 'wh' => $fx['wh'], 'cat' => $fx['cat'], 'items' => $items, 'range' => $fx['range'], 'expect' => $fx['expect']]);
