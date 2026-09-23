<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.10 — multi-unit barcode mapping CRUD + scan resolution, for the
 * Transaction UX Upgrade (searchable item selector / barcode scanning).
 *
 * item_barcodes is purely additive alongside the legacy single
 * items.barcode column (never read or written here). A barcode mapping is
 * never hard-deleted — only deactivated (is_active=0), same convention as
 * bakery_destinations/warehouses/divisions.
 *
 * SECURITY (Part I): resolve() is a SELECTION mechanism only. It never
 * authorizes anything — every caller (the POST /transactions/in|out
 * routes via the normal item_id/unit_id fields) still runs the exact same
 * item-active / unit-valid-for-item / warehouse-scope / permission checks
 * it always has, regardless of whether the item was chosen by typing or by
 * scanning a barcode.
 */
final class ItemBarcodeService
{
    /** All mappings (active + inactive) — read access is gated on INVENTORY_VIEW by the route, not here. */
    public static function listAll(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT b.id, b.item_id, b.unit_id, b.barcode, b.is_active,
                    i.sku AS item_sku, i.name AS item_name, i.status AS item_status,
                    u.code AS unit_code, u.name AS unit_name
             FROM item_barcodes b
             JOIN items i ON i.id = b.item_id
             LEFT JOIN units u ON u.id = b.unit_id
             ORDER BY i.sku, b.barcode'
        )->fetchAll();
    }

    /** @return list<array> every mapping for one item (active + inactive), for the Master Barang detail drawer. */
    public static function listForItem(PDO $pdo, int $itemId): array
    {
        $stmt = $pdo->prepare(
            'SELECT b.id, b.item_id, b.unit_id, b.barcode, b.is_active, u.code AS unit_code, u.name AS unit_name
             FROM item_barcodes b
             LEFT JOIN units u ON u.id = b.unit_id
             WHERE b.item_id = :item_id
             ORDER BY b.is_active DESC, b.barcode'
        );
        $stmt->execute(['item_id' => $itemId]);
        return $stmt->fetchAll();
    }

    /**
     * Resolve a scanned/typed barcode to an item (+ unit, if the mapping is
     * unit-specific). Distinguishes the three C8-specified failure states —
     * callers must map these to the exact required Indonesian messages:
     *   status=UNKNOWN          -> "Barcode tidak terdaftar."
     *   status=ITEM_INACTIVE    -> "Barang tidak aktif."
     *   status=MAPPING_INACTIVE -> "Barcode tidak aktif."
     *   status=OK               -> resolved item/unit
     * Never falls back to a "similar" item — an unresolved barcode is
     * always reported as-is, never silently substituted (Part C8).
     */
    public static function resolve(PDO $pdo, string $barcode): array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return ['status' => 'UNKNOWN'];
        }

        $stmt = $pdo->prepare(
            'SELECT b.id, b.item_id, b.unit_id, b.is_active AS mapping_active,
                    i.sku AS item_sku, i.name AS item_name, i.status AS item_status
             FROM item_barcodes b
             JOIN items i ON i.id = b.item_id
             WHERE b.barcode = :barcode
             ORDER BY b.is_active DESC
             LIMIT 1'
        );
        $stmt->execute(['barcode' => $barcode]);
        $row = $stmt->fetch();

        if ($row === false) {
            return ['status' => 'UNKNOWN'];
        }
        if ((int) $row['mapping_active'] !== 1) {
            return ['status' => 'MAPPING_INACTIVE'];
        }
        if ($row['item_status'] !== 'ACTIVE') {
            return ['status' => 'ITEM_INACTIVE'];
        }

        return [
            'status' => 'OK',
            'item_id' => (int) $row['item_id'],
            'item_sku' => $row['item_sku'],
            'item_name' => $row['item_name'],
            'unit_id' => $row['unit_id'] !== null ? (int) $row['unit_id'] : null,
        ];
    }

    /** @param array{item_id:int, unit_id?:?int, barcode:string, created_by?:?int, username?:string} $p */
    public static function create(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['item_id', 'barcode']);

        $itemId = (int) $p['item_id'];
        $unitId = isset($p['unit_id']) && $p['unit_id'] !== '' && $p['unit_id'] !== null ? (int) $p['unit_id'] : null;
        $barcode = trim((string) $p['barcode']);

        if ($barcode === '') {
            throw new ValidationException(['barcode cannot be blank']);
        }

        self::assertItemExists($pdo, $itemId);
        if ($unitId !== null) {
            self::assertUnitValidForItem($pdo, $itemId, $unitId);
        }
        self::assertNoActiveDuplicate($pdo, $barcode, null);

        $stmt = $pdo->prepare(
            'INSERT INTO item_barcodes (item_id, unit_id, barcode, is_active, created_by, updated_by)
             VALUES (:item_id, :unit_id, :barcode, 1, :created_by, :updated_by)'
        );
        $stmt->execute([
            'item_id' => $itemId,
            'unit_id' => $unitId,
            'barcode' => $barcode,
            'created_by' => $p['created_by'] ?? null,
            'updated_by' => $p['created_by'] ?? null,
        ]);
        $barcodeId = (int) $pdo->lastInsertId();

        AuditService::log(
            $pdo, $p['created_by'] ?? null, $p['username'] ?? 'system', 'ITEM_BARCODE_CREATE',
            'item_barcodes', $barcodeId, null,
            ['item_id' => $itemId, 'unit_id' => $unitId, 'barcode' => $barcode],
            null
        );

        return ['success' => true, 'item_barcode_id' => $barcodeId];
    }

    /** @param array{unit_id?:mixed, barcode?:string, is_active?:mixed, updated_by?:?int, username?:string} $p */
    public static function update(PDO $pdo, int $barcodeId, array $p): array
    {
        $existing = $pdo->prepare('SELECT * FROM item_barcodes WHERE id = :id');
        $existing->execute(['id' => $barcodeId]);
        $before = $existing->fetch();
        if ($before === false) {
            throw new NotFoundException("item barcode {$barcodeId}");
        }

        $itemId = (int) $before['item_id'];
        $barcode = array_key_exists('barcode', $p) ? trim((string) $p['barcode']) : $before['barcode'];
        if ($barcode === '') {
            throw new ValidationException(['barcode cannot be blank']);
        }
        $unitId = array_key_exists('unit_id', $p)
            ? ($p['unit_id'] !== '' && $p['unit_id'] !== null ? (int) $p['unit_id'] : null)
            : ($before['unit_id'] !== null ? (int) $before['unit_id'] : null);
        $isActive = array_key_exists('is_active', $p) ? (int) (bool) $p['is_active'] : (int) $before['is_active'];

        if ($unitId !== null) {
            self::assertUnitValidForItem($pdo, $itemId, $unitId);
        }
        // Only re-check uniqueness when the row will end up (still/newly)
        // active — a row being deactivated, or already inactive and
        // staying inactive, can never collide (active_marker is NULL for it).
        if ($isActive === 1) {
            self::assertNoActiveDuplicate($pdo, $barcode, $barcodeId);
        }

        $pdo->prepare(
            'UPDATE item_barcodes SET unit_id = :unit_id, barcode = :barcode, is_active = :is_active, updated_by = :updated_by WHERE id = :id'
        )->execute([
            'unit_id' => $unitId,
            'barcode' => $barcode,
            'is_active' => $isActive,
            'updated_by' => $p['updated_by'] ?? null,
            'id' => $barcodeId,
        ]);

        AuditService::log(
            $pdo, $p['updated_by'] ?? null, $p['username'] ?? 'system', 'ITEM_BARCODE_UPDATE',
            'item_barcodes', $barcodeId,
            ['unit_id' => $before['unit_id'] !== null ? (int) $before['unit_id'] : null, 'barcode' => $before['barcode'], 'is_active' => (int) $before['is_active']],
            ['unit_id' => $unitId, 'barcode' => $barcode, 'is_active' => $isActive],
            null
        );

        return ['success' => true, 'item_barcode_id' => $barcodeId];
    }

    private static function assertItemExists(PDO $pdo, int $itemId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        if ($stmt->fetchColumn() === false) {
            throw new NotFoundException("item {$itemId}");
        }
    }

    private static function assertUnitValidForItem(PDO $pdo, int $itemId, int $unitId): void
    {
        // Valid = the item's base unit, or a currently-open purchase/middle
        // conversion — the same set GET /items/{id}/units already exposes.
        $stmt = $pdo->prepare(
            'SELECT 1 FROM items WHERE id = :item_id1 AND base_unit_id = :unit_id1
             UNION
             SELECT 1 FROM item_unit_conversions WHERE item_id = :item_id2 AND unit_id = :unit_id2 AND valid_to IS NULL'
        );
        $stmt->execute(['item_id1' => $itemId, 'unit_id1' => $unitId, 'item_id2' => $itemId, 'unit_id2' => $unitId]);
        if ($stmt->fetchColumn() === false) {
            throw new ValidationException(["unit {$unitId} is not a valid unit for item {$itemId}"]);
        }
    }

    /**
     * Backend enforcement of Part C4 — a barcode must never ambiguously
     * resolve to two active mappings. The UNIQUE KEY uq_item_barcodes_active
     * on (barcode, active_marker) is the real, unbypassable guarantee; this
     * pre-check exists only to turn that constraint violation into a clean
     * ValidationException with a clear message rather than a raw SQL error.
     */
    private static function assertNoActiveDuplicate(PDO $pdo, string $barcode, ?int $excludeId): void
    {
        $sql = 'SELECT id FROM item_barcodes WHERE barcode = :barcode AND is_active = 1';
        $params = ['barcode' => $barcode];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            throw new ValidationException(["barcode '{$barcode}' is already assigned to an active mapping"]);
        }
    }
}
