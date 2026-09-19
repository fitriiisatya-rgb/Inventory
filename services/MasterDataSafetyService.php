<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.1 — the single source of truth for "can this master record be
 * permanently deleted?" across all 6 master-data types (items, warehouses,
 * divisions, suppliers, bakery_destinations, categories). Every reference
 * table checked here was found by grepping database/schema.sql for the
 * actual FK column (item_id/warehouse_id/division_id/supplier_id/
 * bakery_destination_id/category_id), not guessed — see the comment above
 * each check* method for the exact table list.
 *
 * A record is deletable only when EVERY check below returns zero rows.
 * If anything references it, the caller (public/index.php's DELETE routes)
 * must refuse the delete and tell the caller to deactivate instead — this
 * is enforced HERE, server-side, not just hidden in the UI; the UI showing
 * or hiding a "Hapus Permanen" button is a convenience, never the actual
 * guard.
 */
final class MasterDataSafetyService
{
    /** @return array{blocked: bool, reasons: string[]} */
    public static function checkItemReferences(PDO $pdo, int $itemId): array
    {
        // item_unit_conversions is deliberately NOT checked here: every item
        // gets a base-identity conversion row at creation time (structural
        // master-data setup, not transaction/business history), so treating
        // it as a blocking reference would make every item permanently
        // undeletable regardless of whether it was ever actually used. It
        // has no downstream FK pointing at it, so the DELETE handler
        // removes an item's own conversion rows in the same operation as
        // the item itself — see 'DELETE /items/{id}' in public/index.php.
        return self::evaluate($pdo, $itemId, [
            'item_price_history' => 'item_id',
            'item_warehouse_stock_policy' => 'item_id',
            'inventory_batches' => 'item_id',
            'inventory_transaction_lines' => 'item_id',
            'stock_opening_lines' => 'item_id',
            'stock_opname_lines' => 'item_id',
            'stock_adjustments' => 'item_id',
            'warehouse_transfer_lines' => 'item_id',
            'production_inputs' => 'item_id',
            'production_outputs' => 'item_id',
            'book_closing_lines' => 'item_id',
        ]);
    }

    /** @return array{blocked: bool, reasons: string[]} */
    public static function checkWarehouseReferences(PDO $pdo, int $warehouseId): array
    {
        $result = self::evaluate($pdo, $warehouseId, [
            'item_warehouse_stock_policy' => 'warehouse_id',
            'inventory_batches' => 'warehouse_id',
            'inventory_transactions' => 'warehouse_id',
            'inventory_transaction_lines' => 'warehouse_id',
            'stock_opening_lines' => 'warehouse_id',
            'stock_opname_sessions' => 'warehouse_id',
            'stock_adjustments' => 'warehouse_id',
            'production_headers' => 'warehouse_id',
            'book_closing_lines' => 'warehouse_id',
        ]);

        // warehouse_transfers has two FK columns pointing at warehouses —
        // checked separately since evaluate() assumes one column per table.
        $transferCount = self::countWhereEither($pdo, 'warehouse_transfers', 'from_warehouse_id', 'to_warehouse_id', $warehouseId);
        if ($transferCount > 0) {
            $result['blocked'] = true;
            $result['reasons'][] = "{$transferCount} row(s) in warehouse_transfers";
        }

        // A STOCK-role user scoped to this warehouse would be orphaned.
        $userCount = self::countWhere($pdo, 'users', 'warehouse_id', $warehouseId);
        if ($userCount > 0) {
            $result['blocked'] = true;
            $result['reasons'][] = "{$userCount} user account(s) scoped to this warehouse";
        }

        return $result;
    }

    /** @return array{blocked: bool, reasons: string[]} */
    public static function checkDivisionReferences(PDO $pdo, int $divisionId): array
    {
        return self::evaluate($pdo, $divisionId, [
            'users' => 'division_id',
            'inventory_transactions' => 'division_id',
            'production_headers' => 'division_id',
        ]);
    }

    /** @return array{blocked: bool, reasons: string[]} */
    public static function checkSupplierReferences(PDO $pdo, int $supplierId): array
    {
        return self::evaluate($pdo, $supplierId, [
            'items' => 'default_supplier_id',
            'item_price_history' => 'supplier_id',
            'inventory_batches' => 'supplier_id',
            'inventory_transactions' => 'supplier_id',
        ]);
    }

    /** @return array{blocked: bool, reasons: string[]} */
    public static function checkBakeryDestinationReferences(PDO $pdo, int $bakeryDestinationId): array
    {
        return self::evaluate($pdo, $bakeryDestinationId, [
            'inventory_transactions' => 'bakery_destination_id',
        ]);
    }

    /** @return array{blocked: bool, reasons: string[]} */
    public static function checkCategoryReferences(PDO $pdo, int $categoryId): array
    {
        return self::evaluate($pdo, $categoryId, [
            'items' => 'category_id',
        ]);
    }

    /** @param array<string,string> $tableColumnMap table name => FK column name */
    private static function evaluate(PDO $pdo, int $id, array $tableColumnMap): array
    {
        $reasons = [];
        foreach ($tableColumnMap as $table => $column) {
            $count = self::countWhere($pdo, $table, $column, $id);
            if ($count > 0) {
                $reasons[] = "{$count} row(s) in {$table}";
            }
        }
        return ['blocked' => $reasons !== [], 'reasons' => $reasons];
    }

    private static function countWhere(PDO $pdo, string $table, string $column, int $id): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :id");
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    private static function countWhereEither(PDO $pdo, string $table, string $columnA, string $columnB, int $id): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$columnA}` = :id OR `{$columnB}` = :id2");
        $stmt->execute(['id' => $id, 'id2' => $id]);
        return (int) $stmt->fetchColumn();
    }
}
