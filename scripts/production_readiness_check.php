<?php
declare(strict_types=1);

/**
 * STABILIZATION — Task 9: production readiness verification.
 *
 * READ-ONLY. Every statement below is a SELECT; nothing here writes,
 * updates, deletes, reposts, or migrates anything — in particular it
 * never touches Stock Opname sessions 11/12 or any other row. Connects
 * through the SAME Database::connection()/.env mechanism every other
 * script and test in this repo already uses; it never prints the DB
 * host, username, or password, or any other secret — only the counts and
 * statuses requested below.
 *
 * Usage (run wherever a real DB connection is configured, including
 * directly against production by someone with its own .env — this
 * script itself never deploys or changes anything):
 *   php scripts/production_readiness_check.php
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$pdo = Database::connection();

function q(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
function scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

echo "============================================================\n";
echo " PRODUCTION READINESS CHECK — read-only, no credentials shown\n";
echo " Generated: " . date('Y-m-d H:i:s') . "\n";
echo "============================================================\n\n";

echo "== WAREHOUSES ==\n";
// Matched by NAME, never by a hardcoded id — a real production id for
// SCM/Cibadak/Karang Tengah can differ from any id used in this repo's
// own disposable test fixtures.
$targetNames = ['SCM', 'Cibadak', 'Karang Tengah'];
foreach ($targetNames as $name) {
    $rows = q($pdo, 'SELECT id, code, name, is_active FROM warehouses WHERE name = :name', ['name' => $name]);
    if ($rows === []) {
        echo "  {$name}: NOT FOUND\n";
        continue;
    }
    foreach ($rows as $row) {
        echo "  {$name}: id={$row['id']} code={$row['code']} active=" . ($row['is_active'] ? 'YES' : 'NO') . "\n";
    }
}
$totalActiveWh = scalar($pdo, "SELECT COUNT(*) FROM warehouses WHERE is_active = 1");
echo "  Total ACTIVE warehouses (all, not just the three above): {$totalActiveWh}\n\n";

echo "== MASTER DATA ==\n";
$itemCount = (int) scalar($pdo, 'SELECT COUNT(*) FROM items');
$activeItemCount = (int) scalar($pdo, "SELECT COUNT(*) FROM items WHERE status = 'ACTIVE'");
$supplierCount = (int) scalar($pdo, 'SELECT COUNT(*) FROM suppliers');
echo "  Item count: {$itemCount}\n";
echo "  Active item count: {$activeItemCount}\n";
echo "  Supplier count: {$supplierCount}\n";

$missingPriceCount = (int) scalar($pdo,
    'SELECT COUNT(*) FROM items i WHERE NOT EXISTS (SELECT 1 FROM item_price_history iph WHERE iph.item_id = i.id)'
);
echo "  Items with NO price history row at all (missing price): {$missingPriceCount}\n";

// "Unmatched supplier count" is only derivable here in the sense of
// items whose default_supplier_id does not resolve to a real supplier
// row — the actual import-time Excel-name-matching report only exists
// at import time (scripts/validate_master_data_centralized.php), so this
// is explicitly the DB-level proxy for it, not a re-run of that import.
$unmatchedSupplierCount = (int) scalar($pdo,
    'SELECT COUNT(*) FROM items i WHERE i.default_supplier_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM suppliers s WHERE s.id = i.default_supplier_id)'
);
echo "  Items whose default_supplier_id does not resolve to a real supplier (unmatched, if any): {$unmatchedSupplierCount}\n";

$missingBaseUnitCount = (int) scalar($pdo,
    'SELECT COUNT(*) FROM items i LEFT JOIN units u ON u.id = i.base_unit_id WHERE u.id IS NULL'
);
echo "  Items missing a valid base unit (should always be 0 — FK-enforced): {$missingBaseUnitCount}\n";

$duplicateSkuCount = (int) scalar($pdo,
    'SELECT COUNT(*) FROM (SELECT sku FROM items GROUP BY sku HAVING COUNT(*) > 1) d'
);
echo "  Duplicate SKU count (should always be 0 — SKU is UNIQUE): {$duplicateSkuCount}\n\n";

echo "== STOCK OPNAME (sessions 11 and 12 — READ ONLY, never modified by this script) ==\n";
foreach ([11, 12] as $sessionId) {
    $session = q($pdo, 'SELECT id, session_number, warehouse_id, status, counting_model, finalized_at, posted_at FROM stock_opname_sessions WHERE id = :id', ['id' => $sessionId]);
    if ($session === []) {
        echo "  Session {$sessionId}: NOT FOUND in this database (expected if checking a non-production DB)\n";
        continue;
    }
    $s = $session[0];
    $warehouseName = scalar($pdo, 'SELECT name FROM warehouses WHERE id = :id', ['id' => $s['warehouse_id']]) ?: '(unknown)';
    echo "  Session {$sessionId} ({$s['session_number']}, warehouse={$warehouseName}): status={$s['status']} counting_model={$s['counting_model']} finalized_at=" . ($s['finalized_at'] ?? 'NULL') . " posted_at=" . ($s['posted_at'] ?? 'NULL') . "\n";

    $adjustmentCount = (int) scalar($pdo,
        'SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = :id AND adjustment_id IS NOT NULL',
        ['id' => $sessionId]
    );
    echo "    Adjustment count: {$adjustmentCount}\n";

    $missingPriceLines = (int) scalar($pdo,
        "SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = :id AND cost_required = 1 AND adjustment_id IS NULL",
        ['id' => $sessionId]
    );
    echo "    Lines still missing a required price (cost_required=1, not yet posted): {$missingPriceLines}\n";
}

echo "\n============================================================\n";
echo " END OF REPORT — no data was modified.\n";
echo "============================================================\n";
