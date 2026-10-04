<?php
declare(strict_types=1);

/**
 * Seed for the Stock IN / Stock OUT V2 browser tests: real warehouses, users, suppliers,
 * a bakery destination (with address/PIC/phone), the three categories of the approved
 * mockup, and items with REAL purchases (so reference prices + FIFO layers exist).
 * Item NAMES are what the operator types — SKUs are deliberately unrelated.
 * Prints one JSON document.
 */
require_once __DIR__ . '/../lib/dashboard_fixture.php';

use App\Services\Database;
use App\Services\UnitConversionService;

$pdo = Database::connection();
$kg = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$pcs = (int) $pdo->query("SELECT id FROM units WHERE code='PCS'")->fetchColumn();
$karton = (int) $pdo->query("SELECT id FROM units WHERE code='KARTON'")->fetchColumn();
$pack = (int) $pdo->query("SELECT id FROM units WHERE code='PACK'")->fetchColumn();
$mkUser = function (string $tag, string $roleCode, ?int $wh = null) use ($pdo): array {
    $role = (int) $pdo->query("SELECT id FROM roles WHERE code='{$roleCode}'")->fetchColumn();
    $u = df_uid($tag);
    $pass = 'Tx' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $role, 'w' => $wh]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
};
$WA = df_wh($pdo, 'Gudang Cibadak');
$WB = df_wh($pdo, 'Gudang SCM');
$admin = $mkUser('txadmin', 'SUPERADMIN');
$stockA = $mkUser('txstockA', 'STOCK', $WA);
$viewer = $mkUser('txviewer', 'VIEWER');
$by = $admin['id'];
$pdo->prepare("INSERT INTO suppliers (code, name, is_active) VALUES (:c,'PT Sinar Makmur',1)")->execute(['c' => df_uid('SUP')]);
$supplier = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO bakery_destinations (code, name, address, pic_name, phone, is_active) VALUES (:c,'Amor Bakery - Pusat','Jl. Sudirman No. 123, Bandung 40111, Jawa Barat','Budi Santoso','0812-3456-7890',1)")->execute(['c' => df_uid('BKR')]);
$bakery = (int) $pdo->lastInsertId();
$cats = [];
foreach (['Packaging', 'Aksesoris', 'Bahan'] as $n) {
    $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c,:n,1)')->execute(['c' => df_uid('CAT'), 'n' => $n]);
    $cats[$n] = (int) $pdo->lastInsertId();
}
function item(PDO $pdo, string $name, int $cat, int $unit, float $price, float $qty, int $wh, int $by, ?int $extraUnit = null, float $factor = 0, bool $withStock = true): array
{
    $it = df_item($pdo, $unit, 'TXI', $cat);
    $pdo->prepare('UPDATE items SET name = :n WHERE id = :i')->execute(['n' => $name, 'i' => $it['id']]);
    if ($extraUnit !== null) {
        UnitConversionService::openNewVersion($pdo, $it['id'], $extraUnit, $factor, '2020-01-01 00:00:00', null, 'x');
    }
    if ($withStock) {
        df_in($it['id'], $wh, $qty, $price, '2026-01-05 08:00:00', $by, $unit, 'IN', 'PO-SEED');
    }
    return ['id' => $it['id'], 'name' => $name];
}
$items = [
    'box' => item($pdo, 'Box Cake 20x20', $cats['Packaging'], $pcs, 4000, 500, $WA, $by),
    'bag' => item($pdo, 'Paper Bag Medium', $cats['Packaging'], $pcs, 1500, 1000, $WA, $by),
    'stiker' => item($pdo, 'Stiker Logo Amor', $cats['Packaging'], $pcs, 300, 2000, $WA, $by),
    'lilin' => item($pdo, 'Lilin Ulang Tahun', $cats['Aksesoris'], $pcs, 2000, 200, $WA, $by),
    'topper' => item($pdo, 'Cake Topper', $cats['Aksesoris'], $pcs, 3500, 300, $WA, $by),
    'tepung' => item($pdo, 'Tepung Terigu', $cats['Bahan'], $kg, 12500, 100, $WA, $by),
    'karung' => item($pdo, 'Gula Pasir Karung', $cats['Bahan'], $kg, 1000, 192, $WA, $by, $karton, 24.0),
    // Stock IN items: reference prices exist, some in a non-base unit
    'terigu' => item($pdo, 'Tepung Terigu Segitiga Biru', $cats['Bahan'], $kg, 12500, 10, $WB, $by, $karton, 24.0),
    'gula' => item($pdo, 'Gula Pasir', $cats['Bahan'], $kg, 14000, 10, $WB, $by),
    'ragi' => item($pdo, 'Ragi Instan', $cats['Bahan'], $pack, 8000, 10, $WB, $by),
    'baru' => item($pdo, 'Barang Tanpa Riwayat Harga', $cats['Packaging'], $pcs, 0, 0, $WB, $by, null, 0, false),
];
echo json_encode(['admin' => $admin, 'stockA' => $stockA, 'viewer' => $viewer, 'wh' => ['A' => $WA, 'B' => $WB], 'supplier' => $supplier, 'bakery' => $bakery, 'cats' => $cats, 'items' => $items, 'units' => ['kg' => $kg, 'karton' => $karton]]);
