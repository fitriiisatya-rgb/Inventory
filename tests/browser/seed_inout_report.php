<?php
declare(strict_types=1);

/** Seed for the Laporan IN / OUT / Transfer browser test: real postings (tests/lib/inout_report_fixture.php). Prints one JSON document. */
require_once __DIR__ . '/../lib/inout_report_fixture.php';

use App\Services\Database;

$pdo = Database::connection();
$fx = inout_build_fixture($pdo);
$items = [];
foreach ($fx['items'] as $k => $i) {
    $items[$k] = ['id' => $i['id'], 'sku' => $i['sku'], 'name' => $i['name']];
}
$out = [];
foreach ($fx['out'] as $k => $o) {
    $out[$k] = ['do_id' => $o['do_id'], 'do_number' => $o['do_number'], 'invoice_number' => $o['invoice_number'], 'invoice_id' => $o['invoice_id']];
}
echo json_encode(['admin' => $fx['admin'], 'viewer' => $fx['viewer'], 'stock2' => $fx['stock2'], 'wh' => $fx['wh'], 'cat' => $fx['cat'], 'sup' => $fx['sup'], 'bakery' => $fx['bakery'], 'items' => $items,
    'out' => $out, 'trf' => $fx['trf'], 'tx' => $fx['tx'], 'range' => $fx['range'], 'expect' => $fx['expect'], 'io_expect' => $fx['io_expect']]);
