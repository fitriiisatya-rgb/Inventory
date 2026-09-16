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
