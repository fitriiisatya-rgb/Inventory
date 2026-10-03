<?php
declare(strict_types=1);

/**
 * STABILIZATION — seeds tests/browser/playwright_stabilization_editbarang_units.mjs.
 * One SUPERADMIN, one category, one NORMAL item (base-unit identity
 * conversion correctly set up, as the real import script always does),
 * and one item with ZERO item_unit_conversions rows at all (a deliberate
 * data gap — inserted directly, bypassing UnitConversionService, to
 * reproduce the "Satuan untuk Harga" empty-dropdown case on an item that
 * never got its identity conversion seeded).
 */

require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/Exceptions.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/UnitConversionService.php';

use App\Services\Database;
use App\Services\UnitConversionService;

function uid(string $p): string { return $p . '-' . bin2hex(random_bytes(3)); }

$pdo = Database::connection();

$superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

$pass = 'StbEbTest' . bin2hex(random_bytes(4)) . '!1';
$username = uid('stbeb-admin');
$pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES (:u,:h,:n,:r,1)')
    ->execute(['u' => $username, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $username, 'r' => $superRoleId]);
$adminId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => uid('STBEB-CAT'), 'n' => 'Stabilization EB Kategori']);
$catId = (int) $pdo->lastInsertId();

// Normal item — base-unit identity conversion correctly seeded (the
// real-world path every item created via the app or the import script
// actually takes).
$skuNormal = uid('STBEB-NORMAL');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:sku,:name,:unit,:cat,0,\'ACTIVE\')')
    ->execute(['sku' => $skuNormal, 'name' => "Item {$skuNormal}", 'unit' => $kgUnitId, 'cat' => $catId]);
$itemNormalId = (int) $pdo->lastInsertId();
Database::transaction(fn (PDO $tx) => UnitConversionService::openNewVersion($tx, $itemNormalId, $kgUnitId, 1.0, '2020-01-01 00:00:00', null, 'base identity'));

// Item with a data gap — literally zero item_unit_conversions rows,
// inserted directly (bypassing UnitConversionService entirely) to
// reproduce the exact gap this round's fix is meant to self-heal.
$skuNoConv = uid('STBEB-NOCONV');
$pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:sku,:name,:unit,:cat,0,\'ACTIVE\')')
    ->execute(['sku' => $skuNoConv, 'name' => "Item {$skuNoConv}", 'unit' => $kgUnitId, 'cat' => $catId]);
$itemNoConvId = (int) $pdo->lastInsertId();
// Deliberately: no UnitConversionService::openNewVersion() call here.

echo json_encode([
    'admin' => ['username' => $username, 'password' => $pass],
    'sku_normal' => $skuNormal,
    'sku_no_conversions' => $skuNoConv,
]);
