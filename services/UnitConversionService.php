<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Section 4/5: base unit is immutable math anchor; purchase/middle units are
 * always expressed as a *versioned* conversion back to base, so a packaging
 * change never rewrites the cost of transactions already posted.
 */
final class UnitConversionService
{
    /**
     * The conversion row active at $asOf (business-effective date), or null
     * if the item has no defined conversion for that unit at that time.
     */
    public static function getActiveConversion(PDO $pdo, int $itemId, int $unitId, string $asOf): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM item_unit_conversions
             WHERE item_id = :item_id AND unit_id = :unit_id
               AND valid_from <= :as_of
               AND (valid_to IS NULL OR valid_to > :as_of2)
             ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute(['item_id' => $itemId, 'unit_id' => $unitId, 'as_of' => $asOf, 'as_of2' => $asOf]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Opens a new packaging/conversion version, closing whatever version was
     * previously open for this item+unit. Never mutates a closed version's
     * conversion_to_base — that would silently reprice every historical
     * transaction that snapshotted it.
     */
    public static function openNewVersion(
        PDO $pdo,
        int $itemId,
        int $unitId,
        float $conversionToBase,
        string $validFrom,
        ?int $createdBy,
        ?string $note = null,
        bool $isPurchaseDefault = false
    ): int {
        if ($conversionToBase <= 0) {
            throw new ValidationException(['conversion_to_base must be > 0']);
        }

        $current = $pdo->prepare(
            'SELECT id, valid_from FROM item_unit_conversions
             WHERE item_id = :item_id AND unit_id = :unit_id AND valid_to IS NULL LIMIT 1'
        );
        $current->execute(['item_id' => $itemId, 'unit_id' => $unitId]);
        $open = $current->fetch();

        if ($open && $open['valid_from'] >= $validFrom) {
            throw new ValidationException(['new version must start after the currently open version']);
        }

        if ($open) {
            $close = $pdo->prepare('UPDATE item_unit_conversions SET valid_to = :valid_to WHERE id = :id');
            $close->execute(['valid_to' => $validFrom, 'id' => $open['id']]);
        }

        $insert = $pdo->prepare(
            'INSERT INTO item_unit_conversions
                (item_id, unit_id, conversion_to_base, is_purchase_default, valid_from, valid_to, note, created_by, created_at)
             VALUES (:item_id, :unit_id, :factor, :is_default, :valid_from, NULL, :note, :created_by, :created_at)'
        );
        $insert->execute([
            'item_id'    => $itemId,
            'unit_id'    => $unitId,
            'factor'     => $conversionToBase,
            'is_default' => $isPurchaseDefault ? 1 : 0,
            'valid_from' => $validFrom,
            'note'       => $note,
            'created_by' => $createdBy,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * STABILIZATION — resolves the conversion factor to use for item_id/
     * unit_id as of $asOf, for QTY/COST MATH ONLY (FifoService,
     * DistributionOrderService, PurchaseCostingGateway — i.e. every
     * caller that needs "what factor do I multiply by", not "does a
     * stored row exist").
     *
     * Root cause this fixes: items.base_unit_id is already the canonical
     * qty anchor for its item — by definition, 1 base unit = 1 base unit,
     * factor 1 — but FifoService/DistributionOrderService/
     * PurchaseCostingGateway each previously required getActiveConversion()
     * to find a REAL item_unit_conversions row even for the base unit
     * itself, and threw UnitConversionNotApprovedException otherwise.
     * item_unit_conversions was only ever meant to hold "the purchase/
     * middle unit being defined" (see its own schema comment) — a
     * base-unit identity row is optional, and production confirms 661
     * real items have no such row. This method returns 1.0 for the base
     * unit intrinsically, whether or not a row exists; every OTHER unit
     * is completely unchanged and still requires a real, approved,
     * currently-open row, returning null exactly as before when one is
     * missing — the caller still decides what to do with that null
     * (ValidationException/UnitConversionNotApprovedException, same as
     * always).
     *
     * Deliberately NOT folded into getActiveConversion() itself: that
     * method answers "does a REAL stored row exist?" and is also used by
     * write paths (Edit Barang's PUT /items/{id}, the centralized import)
     * to decide whether to INSERT a new row — those must keep seeing null
     * for an unrecorded base-unit case, or an admin explicitly adding a
     * real identity row through Edit Barang would be silently skipped as
     * a no-op the moment this method existed instead.
     */
    public static function resolveConversionFactor(PDO $pdo, int $itemId, int $unitId, string $asOf): ?float
    {
        $existing = self::getActiveConversion($pdo, $itemId, $unitId, $asOf);
        if ($existing !== null) {
            return (float) $existing['conversion_to_base'];
        }

        $itemStmt = $pdo->prepare('SELECT base_unit_id FROM items WHERE id = :id');
        $itemStmt->execute(['id' => $itemId]);
        $baseUnitId = (int) $itemStmt->fetchColumn();

        return $baseUnitId === $unitId ? 1.0 : null;
    }

    /** Section 5: base unit itself may not change once the item has posted transactions. */
    public static function changeBaseUnit(PDO $pdo, int $itemId, int $newBaseUnitId, bool $forceUnlocked = false): void
    {
        $stmt = $pdo->prepare('SELECT locked_at FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        $item = $stmt->fetch();

        if ($item && $item['locked_at'] !== null && !$forceUnlocked) {
            throw new ItemLockedException('base_unit_id');
        }

        $update = $pdo->prepare('UPDATE items SET base_unit_id = :unit_id WHERE id = :id');
        $update->execute(['unit_id' => $newBaseUnitId, 'id' => $itemId]);
    }

    /** Called once, the first time a transaction line successfully posts for this item. */
    public static function lockItemIfNeeded(PDO $pdo, int $itemId): void
    {
        $stmt = $pdo->prepare('UPDATE items SET locked_at = :now WHERE id = :id AND locked_at IS NULL');
        $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $itemId]);
    }
}
