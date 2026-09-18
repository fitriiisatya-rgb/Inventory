<?php
declare(strict_types=1);

/**
 * PRODUCTION CUTOVER RUNBOOK — STEP 8 verification. Read-only: reports the
 * Global Item Master import's actual state after commit, so the operator
 * can check the exact numbers before proceeding to STEP 9. Never writes
 * anything.
 *
 * Usage: php scripts/cutover_verify_master_import.php
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$pdo = Database::connection();

$total = (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();

$dupSku = (int) $pdo->query(
    'SELECT COUNT(*) FROM (SELECT sku FROM items GROUP BY sku HAVING COUNT(*) > 1) t'
)->fetchColumn();

$invalidBaseUnit = (int) $pdo->query(
    'SELECT COUNT(*) FROM items i LEFT JOIN units u ON u.id = i.base_unit_id WHERE u.id IS NULL'
)->fetchColumn();

$noIdentityConversion = (int) $pdo->query(
    "SELECT COUNT(*) FROM items i
     WHERE NOT EXISTS (
        SELECT 1 FROM item_unit_conversions c
        WHERE c.item_id = i.id AND c.unit_id = i.base_unit_id AND c.valid_to IS NULL
     )"
)->fetchColumn();

$approvedConversions = (int) $pdo->query(
    "SELECT COUNT(*) FROM item_unit_conversions c
     JOIN items i ON i.id = c.item_id
     WHERE c.unit_id <> i.base_unit_id AND c.valid_to IS NULL AND c.is_purchase_default = 1"
)->fetchColumn();

echo json_encode([
    'total_items' => $total,
    'duplicate_sku' => $dupSku,
    'invalid_or_missing_base_unit' => $invalidBaseUnit,
    'items_missing_identity_conversion' => $noIdentityConversion,
    'approved_alternate_purchase_conversions_created' => $approvedConversions,
], JSON_PRETTY_PRINT) . "\n";

$ok = $dupSku === 0 && $invalidBaseUnit === 0 && $noIdentityConversion === 0;
echo $ok ? "\nOK — proceed to STEP 9.\n" : "\nSTOP — one or more counts above is non-zero. Do not proceed.\n";
exit($ok ? 0 : 1);
