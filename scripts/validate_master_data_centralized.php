<?php
declare(strict_types=1);

/**
 * CENTRALIZED MASTER DATA — validation queries (Task 7). Pure SELECTs
 * only; never writes anything. Run after
 * scripts/import_master_data_centralized.php to sanity-check the result,
 * or at any time to audit the current state of the centralized master.
 *
 * Usage:
 *   php scripts/validate_master_data_centralized.php
 *   php scripts/validate_master_data_centralized.php <master_barang.xlsx> <master_supplier.xlsx>
 *     (adds the source-file cross-checks: unmatched supplier names,
 *      invalid unit conversion rows — the same read-only logic the
 *      import script's own reporting uses, re-derived here independently
 *      so this script is a genuine second check, not just an echo)
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';

use App\Services\Database;
use App\Services\UnitNormalizationService;
use App\Services\XlsxReaderService;

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n\n";

function q(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

echo "== ITEM COUNTS ==\n";
$totalItems = q($pdo, 'SELECT COUNT(*) FROM items');
$activeItems = q($pdo, "SELECT COUNT(*) FROM items WHERE status = 'ACTIVE'");
$inactiveItems = $totalItems - $activeItems;
echo "Total items: {$totalItems}\n";
echo "Active items: {$activeItems}\n";
echo "Inactive items: {$inactiveItems}\n";

// Structurally impossible via the FK (items.base_unit_id NOT NULL
// REFERENCES units(id)) — still queried explicitly, never assumed, so a
// future schema change can't silently invalidate this check.
$noBaseUnit = q($pdo, 'SELECT COUNT(*) FROM items i LEFT JOIN units u ON u.id = i.base_unit_id WHERE u.id IS NULL');
echo "Items without a valid base unit (should always be 0 — FK-enforced): {$noBaseUnit}\n";

$noCost = q($pdo, 'SELECT COUNT(*) FROM items i WHERE NOT EXISTS (SELECT 1 FROM item_price_history iph WHERE iph.item_id = i.id)');
$noCostActive = q($pdo, "SELECT COUNT(*) FROM items i WHERE i.status = 'ACTIVE' AND NOT EXISTS (SELECT 1 FROM item_price_history iph WHERE iph.item_id = i.id)");
echo "Items without ANY unit cost / HPP history row: {$noCost} (of which ACTIVE: {$noCostActive})\n";

$dupSku = q($pdo, 'SELECT COUNT(*) FROM (SELECT sku FROM items GROUP BY sku HAVING COUNT(*) > 1) x');
echo "Duplicate SKU in items table (should always be 0 — UNIQUE-enforced): {$dupSku}\n\n";

echo "== SUPPLIER COUNTS ==\n";
$totalSuppliers = q($pdo, 'SELECT COUNT(*) FROM suppliers');
$activeSuppliers = q($pdo, 'SELECT COUNT(*) FROM suppliers WHERE is_active = 1');
echo "Total suppliers: {$totalSuppliers}\n";
echo "Active suppliers: {$activeSuppliers}\n";

$noSupplier = q($pdo, 'SELECT COUNT(*) FROM items WHERE default_supplier_id IS NULL');
$noSupplierActive = q($pdo, "SELECT COUNT(*) FROM items WHERE status = 'ACTIVE' AND default_supplier_id IS NULL");
echo "Items without a supplier: {$noSupplier} (of which ACTIVE: {$noSupplierActive})\n\n";

echo "== CENTRALIZATION STRUCTURE PROOF ==\n";
$itemsHasWarehouseColumn = $pdo->query("SHOW COLUMNS FROM items LIKE 'warehouse_id'")->rowCount();
echo "items.warehouse_id column exists: " . ($itemsHasWarehouseColumn > 0 ? 'YES (!! would mean per-warehouse duplication)' : 'NO (confirmed — items are a single global master, not duplicated per warehouse)') . "\n";
$suppliersHasWarehouseColumn = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'warehouse_id'")->rowCount();
echo "suppliers.warehouse_id column exists: " . ($suppliersHasWarehouseColumn > 0 ? 'YES (!! would mean per-warehouse duplication)' : 'NO (confirmed global)') . "\n";

$stockRoleViewPerm = $pdo->query(
    "SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
     WHERE r.code = 'STOCK' AND p.code = 'INVENTORY_VIEW'"
)->fetchColumn();
echo "STOCK role holds INVENTORY_VIEW (the permission GET /items and GET /suppliers require): " . ((int) $stockRoleViewPerm > 0 ? 'YES' : 'NO — regression!') . "\n";
echo "(A STOCK-role user at ANY warehouse — SCM, Cibadak, or Karang Tengah — therefore reads the exact same item/supplier master; see the dedicated 3-warehouse HTTP test for the live proof.)\n\n";

if ($argc >= 3) {
    require_once __DIR__ . '/../services/XlsxReaderService.php';

    $barangPath = $argv[1];
    $supplierPath = $argv[2];

    echo "== SOURCE-FILE CROSS-CHECK (" . basename($barangPath) . " / " . basename($supplierPath) . ") ==\n";

    $supplierNames = [];
    foreach (XlsxReaderService::read($supplierPath) as $row) {
        $n = trim((string) ($row['NAMA SUPPLIER'] ?? ''));
        if ($n !== '') {
            $supplierNames[mb_strtolower($n)] = true;
        }
    }

    $unmatchedSuppliers = [];
    $invalidUnitRows = [];
    $itemRows = XlsxReaderService::read($barangPath);
    foreach ($itemRows as $row) {
        $sku = trim((string) ($row['KODE BAHAN'] ?? ''));
        $distributor = trim((string) ($row['DISTRIBUTOR'] ?? ''));
        if ($distributor !== '' && !isset($supplierNames[mb_strtolower($distributor)])) {
            $unmatchedSuppliers[$distributor] = ($unmatchedSuppliers[$distributor] ?? 0) + 1;
        }
        foreach (['SATUAN DASAR HPP' => 'base_unit', 'KEMASAN BELI' => 'purchase_unit', 'SATUAN KEMASAN' => 'middle_unit'] as $col => $label) {
            $raw = trim((string) ($row[$col] ?? ''));
            if ($raw !== '' && UnitNormalizationService::normalize($pdo, $raw) === null) {
                $invalidUnitRows[] = "{$sku}: {$label}='{$raw}' not recognized";
            }
        }
    }

    echo 'Unmatched supplier names (DISTRIBUTOR not in supplier master file): ' . count($unmatchedSuppliers) . " distinct name(s)\n";
    foreach ($unmatchedSuppliers as $name => $count) {
        echo "  - \"{$name}\" ({$count} item row(s))\n";
    }
    echo 'Rows with an invalid/unrecognized unit (base/purchase/middle): ' . count($invalidUnitRows) . "\n";
    foreach (array_slice($invalidUnitRows, 0, 20) as $line) {
        echo "  - {$line}\n";
    }
    if (count($invalidUnitRows) > 20) {
        echo '  - … and ' . (count($invalidUnitRows) - 20) . " more\n";
    }
}

echo "\nDone.\n";
