<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Master Data "Tambah ..." (create) write paths that had no endpoint yet: Barang, Gudang, Divisi.
 * (Supplier, Bakery Tujuan and Kategori already had POST routes — reused unchanged.)
 *
 * Reuses the existing building blocks instead of inventing parallel logic:
 *   - UnitConversionService::openNewVersion() for the base-unit identity row and the purchase
 *     conversion (exactly what the Master Item import commit does; same backdated valid_from so a
 *     back-dated Stock IN of a freshly created item is not refused as UNIT_CONVERSION_NOT_APPROVED);
 *   - the same append-only item_price_history insert PUT /items/{id} uses for "Harga Beli" — there is
 *     no second price system; the price is optional and may be 0;
 *   - AuditService for every creation.
 * Called inside Database::transaction() by the routes: any failure rolls the whole creation back.
 * Master Barang stays centralised: nothing here creates a warehouse-specific copy of an item.
 */
final class MasterRecordService
{
    private const CONVERSION_VALID_FROM = '2000-01-01 00:00:00';

    /** @param array<string,mixed> $p created_by/username are set by the route from the session */
    public static function createItem(PDO $pdo, array $p): array
    {
        $errors = [];
        $sku = trim((string) ($p['sku'] ?? ''));
        $name = trim((string) ($p['name'] ?? ''));
        if ($sku === '') {
            $errors[] = 'sku is required';
        } elseif (mb_strlen($sku) > 40) {
            $errors[] = 'sku must be at most 40 characters';
        } elseif (preg_match('/\s/', $sku)) {
            $errors[] = 'sku must not contain spaces';
        }
        if ($name === '') {
            $errors[] = 'name is required';
        } elseif (mb_strlen($name) > 200) {
            $errors[] = 'name must be at most 200 characters';
        }

        $categoryId = isset($p['category_id']) && $p['category_id'] !== '' ? (int) $p['category_id'] : 0;
        $category = null;
        if ($categoryId <= 0) {
            $errors[] = 'category_id is required';
        } else {
            $category = self::row($pdo, 'SELECT id, name, is_active FROM categories WHERE id = :id', $categoryId);
            if ($category === null) {
                $errors[] = "category_id {$categoryId} does not exist";
            } elseif ((int) $category['is_active'] !== 1) {
                $errors[] = "category_id {$categoryId} is inactive";
            }
        }

        // Supplier: chosen from the supplier master, never auto-created, optional.
        $supplierId = isset($p['default_supplier_id']) && $p['default_supplier_id'] !== '' && $p['default_supplier_id'] !== null ? (int) $p['default_supplier_id'] : null;
        if ($supplierId !== null) {
            $supplier = self::row($pdo, 'SELECT id, is_active FROM suppliers WHERE id = :id', $supplierId);
            if ($supplier === null) {
                $errors[] = "default_supplier_id {$supplierId} does not exist in the supplier master";
            } elseif ((int) $supplier['is_active'] !== 1) {
                $errors[] = "default_supplier_id {$supplierId} is inactive";
            }
        }

        $baseUnitId = isset($p['base_unit_id']) && $p['base_unit_id'] !== '' ? (int) $p['base_unit_id'] : 0;
        if ($baseUnitId <= 0) {
            $errors[] = 'base_unit_id is required';
        } elseif (self::row($pdo, 'SELECT id FROM units WHERE id = :id', $baseUnitId) === null) {
            $errors[] = "base_unit_id {$baseUnitId} is not a recognized unit";
        }

        // Purchase unit + conversion come as a pair (1 purchase unit = N base units).
        $purchaseUnitId = isset($p['purchase_unit_id']) && $p['purchase_unit_id'] !== '' && $p['purchase_unit_id'] !== null ? (int) $p['purchase_unit_id'] : null;
        $purchaseFactor = isset($p['purchase_conversion']) && $p['purchase_conversion'] !== '' && $p['purchase_conversion'] !== null ? $p['purchase_conversion'] : null;
        if ($purchaseUnitId !== null) {
            if (self::row($pdo, 'SELECT id FROM units WHERE id = :id', $purchaseUnitId) === null) {
                $errors[] = "purchase_unit_id {$purchaseUnitId} is not a recognized unit";
            } elseif ($purchaseUnitId === $baseUnitId) {
                $errors[] = 'purchase_unit_id must differ from the base unit';
            }
            if ($purchaseFactor === null || !is_numeric($purchaseFactor) || (float) $purchaseFactor <= 0) {
                $errors[] = 'purchase_conversion must be a number > 0 when a purchase unit is chosen';
            }
        } elseif ($purchaseFactor !== null) {
            $errors[] = 'purchase_unit_id is required when purchase_conversion is given';
        }

        // Harga Beli: optional (may stay blank), never negative; attached to the purchase unit if there is
        // one, else to the base unit. Same append-only item_price_history row PUT /items/{id} writes.
        $price = null;
        if (array_key_exists('price', $p) && $p['price'] !== null && $p['price'] !== '') {
            if (!is_numeric($p['price'])) {
                $errors[] = 'price must be numeric';
            } elseif ((float) $p['price'] < 0) {
                $errors[] = 'price cannot be negative';
            } else {
                $price = (float) $p['price'];
            }
        }

        $status = strtoupper((string) ($p['status'] ?? 'ACTIVE'));
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            $errors[] = "status must be ACTIVE or INACTIVE, got '{$status}'";
        }
        if ($sku !== '' && !$errors) {
            $dupe = $pdo->prepare('SELECT id FROM items WHERE sku = :s');
            $dupe->execute(['s' => $sku]);
            if ($dupe->fetchColumn() !== false) {
                $errors[] = "sku '{$sku}' already exists";
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $now = date('Y-m-d H:i:s');
        try {
            $pdo->prepare(
                'INSERT INTO items (sku, name, category, category_id, base_unit_id, minimum_stock, default_supplier_id, notes, status, created_at, updated_at)
                 VALUES (:sku, :name, :category, :category_id, :base_unit_id, 0, :supplier, :notes, :status, :now, :now2)'
            )->execute([
                'sku' => $sku, 'name' => $name, 'category' => $category['name'], 'category_id' => $categoryId,
                'base_unit_id' => $baseUnitId, 'supplier' => $supplierId,
                'notes' => self::nullableTrim($p['notes'] ?? null), 'status' => $status, 'now' => $now, 'now2' => $now,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException(["sku '{$sku}' already exists"]);
            }
            throw $e;
        }
        $itemId = (int) $pdo->lastInsertId();
        $createdBy = isset($p['created_by']) ? (int) $p['created_by'] : null;

        UnitConversionService::openNewVersion($pdo, $itemId, $baseUnitId, 1.0, self::CONVERSION_VALID_FROM, $createdBy, 'identity (base unit)');
        if ($purchaseUnitId !== null) {
            UnitConversionService::openNewVersion($pdo, $itemId, $purchaseUnitId, (float) $purchaseFactor, self::CONVERSION_VALID_FROM, $createdBy, 'Tambah Barang', true);
        }

        $priceRecorded = null;
        if ($price !== null) {
            $priceUnitId = $purchaseUnitId ?? $baseUnitId;
            $factor = $purchaseUnitId !== null ? (float) $purchaseFactor : 1.0;
            $unitCostBase = round($price / $factor, 4);
            $pdo->prepare(
                'INSERT INTO item_price_history (item_id, supplier_id, unit_id, price_per_unit, unit_cost_base, effective_date, created_at)
                 VALUES (:item_id, :supplier_id, :unit_id, :price_per_unit, :unit_cost_base, NOW(), NOW())'
            )->execute([
                'item_id' => $itemId, 'supplier_id' => $supplierId, 'unit_id' => $priceUnitId,
                'price_per_unit' => $price, 'unit_cost_base' => $unitCostBase,
            ]);
            $priceRecorded = ['unit_id' => $priceUnitId, 'price_per_unit' => $price, 'unit_cost_base' => $unitCostBase];
        }

        $username = (string) ($p['username'] ?? 'system');
        AuditService::log($pdo, $createdBy, $username, 'ITEM_CREATE', 'items', $itemId, null, [
            'sku' => $sku, 'name' => $name, 'category_id' => $categoryId, 'default_supplier_id' => $supplierId,
            'base_unit_id' => $baseUnitId, 'purchase_unit_id' => $purchaseUnitId, 'purchase_conversion' => $purchaseFactor !== null ? (float) $purchaseFactor : null, 'status' => $status,
        ], 'Tambah Barang');
        if ($priceRecorded !== null) {
            AuditService::log($pdo, $createdBy, $username, 'ITEM_PRICE_UPDATE', 'items', $itemId, ['unit_cost_base' => null], $priceRecorded, 'Tambah Barang');
        }

        return ['success' => true, 'item_id' => $itemId];
    }

    /** @param array<string,mixed> $p */
    public static function createWarehouse(PDO $pdo, array $p): array
    {
        $errors = self::codeNameErrors($p, 30, 100, 'warehouse');
        $type = strtoupper((string) ($p['warehouse_type'] ?? 'MAIN'));
        if (!in_array($type, ['MAIN', 'TRANSIT'], true)) {
            $errors[] = "warehouse_type must be MAIN or TRANSIT, got '{$type}'";
        }
        $code = trim((string) ($p['code'] ?? ''));
        if (!$errors) {
            self::assertCodeFree($pdo, 'warehouses', $code, "warehouse code '{$code}' already exists", $errors);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $name = trim((string) $p['name']);
        $isActive = array_key_exists('is_active', $p) ? (int) (bool) $p['is_active'] : 1;
        try {
            // activation_locked stays at its default 0: the cutover lock is only ever set by the cutover workflow.
            $pdo->prepare('INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES (:c, :n, :t, :a)')
                ->execute(['c' => $code, 'n' => $name, 't' => $type, 'a' => $isActive]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException(["warehouse code '{$code}' already exists"]);
            }
            throw $e;
        }
        $id = (int) $pdo->lastInsertId();
        AuditService::log($pdo, isset($p['created_by']) ? (int) $p['created_by'] : null, (string) ($p['username'] ?? 'system'), 'WAREHOUSE_CREATE', 'warehouses', $id, null,
            ['code' => $code, 'name' => $name, 'warehouse_type' => $type, 'is_active' => $isActive], 'Tambah Gudang');
        return ['success' => true, 'warehouse_id' => $id];
    }

    /** @param array<string,mixed> $p */
    public static function createDivision(PDO $pdo, array $p): array
    {
        $errors = self::codeNameErrors($p, 30, 100, 'division');
        $code = trim((string) ($p['code'] ?? ''));
        if (!$errors) {
            self::assertCodeFree($pdo, 'divisions', $code, "division code '{$code}' already exists", $errors);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $name = trim((string) $p['name']);
        $isActive = array_key_exists('is_active', $p) ? (int) (bool) $p['is_active'] : 1;
        try {
            $pdo->prepare('INSERT INTO divisions (code, name, is_active) VALUES (:c, :n, :a)')->execute(['c' => $code, 'n' => $name, 'a' => $isActive]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException(["division code '{$code}' already exists"]);
            }
            throw $e;
        }
        $id = (int) $pdo->lastInsertId();
        AuditService::log($pdo, isset($p['created_by']) ? (int) $p['created_by'] : null, (string) ($p['username'] ?? 'system'), 'DIVISION_CREATE', 'divisions', $id, null,
            ['code' => $code, 'name' => $name, 'is_active' => $isActive], 'Tambah Divisi');
        return ['success' => true, 'division_id' => $id];
    }

    /** @return string[] */
    private static function codeNameErrors(array $p, int $codeMax, int $nameMax, string $label): array
    {
        $errors = [];
        $code = trim((string) ($p['code'] ?? ''));
        $name = trim((string) ($p['name'] ?? ''));
        if ($code === '') {
            $errors[] = 'code is required';
        } elseif (mb_strlen($code) > $codeMax) {
            $errors[] = "code must be at most {$codeMax} characters";
        } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]*$/', $code)) {
            $errors[] = 'code may only contain letters, digits, dot, dash and underscore';
        }
        if ($name === '') {
            $errors[] = 'name is required';
        } elseif (mb_strlen($name) > $nameMax) {
            $errors[] = "name must be at most {$nameMax} characters";
        }
        return $errors;
    }

    /** @param string[] $errors */
    private static function assertCodeFree(PDO $pdo, string $table, string $code, string $message, array &$errors): void
    {
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE code = :c");
        $stmt->execute(['c' => $code]);
        if ($stmt->fetchColumn() !== false) {
            $errors[] = $message;
        }
    }

    /** @return array<string,mixed>|null */
    private static function row(PDO $pdo, string $sql, int $id): ?array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch();
        return $r === false ? null : $r;
    }

    private static function nullableTrim(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim((string) $v);
        return $t === '' ? null : $t;
    }
}
