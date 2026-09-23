<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.10 — Stock IN auto-fill reference purchase price (Part B).
 *
 * PRICE-SOURCE AUDIT RESULT (Part B1): the codebase has no "master/default
 * purchase price" field anywhere, and selling price does not exist in this
 * schema at all. The only legitimate existing candidate is
 * item_price_history.price_per_unit — the latest REAL purchase transaction
 * price, already populated by PriceAnomalyService::recordPrice() on every
 * posted Stock IN line, and already independently read (for an unrelated
 * purpose — anomaly detection) by PriceAnomalyService::evaluate(). This
 * service reads the exact same table read-only; it never writes to it,
 * and PriceAnomalyService is never modified.
 *
 * FIFO/HPP cost is explicitly NOT used here (excluded by the feature spec)
 * and this service never touches inventory_batches/unit_cost_base derived
 * from FIFO consumption.
 *
 * UNIT CORRECTNESS (Part B9): item_price_history stores both the original
 * price_per_unit (in whatever unit that historical purchase used) AND
 * unit_cost_base (that price re-expressed per BASE unit, already computed
 * at write time by PriceAnomalyService). Resolving a price for a
 * DIFFERENT target unit therefore never re-derives from a raw price_per_unit
 * in a mismatched unit — it always goes through the base-unit-normalized
 * unit_cost_base and re-multiplies by the target unit's own
 * conversion_to_base, exactly the same math FifoService/UnitConversionService
 * use everywhere else for unit<->base conversion.
 */
final class ItemPriceService
{
    /**
     * @return array{reference_price: ?float, price_source: string, reference_unit_id: ?int}
     *   price_source is one of:
     *     'EXACT_UNIT'  — most recent purchase actually made in this exact unit
     *     'DERIVED'     — no purchase ever made in this unit; derived from the
     *                     most recent purchase (any unit) via unit_cost_base
     *     'NONE'        — no purchase history exists for this item at all
     */
    public static function resolveReferencePrice(PDO $pdo, int $itemId, int $unitId): array
    {
        $exact = $pdo->prepare(
            'SELECT price_per_unit FROM item_price_history
             WHERE item_id = :item_id AND unit_id = :unit_id
             ORDER BY effective_date DESC, id DESC
             LIMIT 1'
        );
        $exact->execute(['item_id' => $itemId, 'unit_id' => $unitId]);
        $exactPrice = $exact->fetchColumn();
        if ($exactPrice !== false) {
            return [
                'reference_price' => round((float) $exactPrice, 4),
                'price_source' => 'EXACT_UNIT',
                'reference_unit_id' => $unitId,
            ];
        }

        $latestAny = $pdo->prepare(
            'SELECT unit_cost_base FROM item_price_history
             WHERE item_id = :item_id
             ORDER BY effective_date DESC, id DESC
             LIMIT 1'
        );
        $latestAny->execute(['item_id' => $itemId]);
        $unitCostBase = $latestAny->fetchColumn();
        if ($unitCostBase === false) {
            return ['reference_price' => null, 'price_source' => 'NONE', 'reference_unit_id' => null];
        }

        $conversion = self::conversionToBase($pdo, $itemId, $unitId);
        if ($conversion === null) {
            // Target unit isn't a recognized unit for this item at all —
            // never guess a conversion; report as if nothing were found.
            return ['reference_price' => null, 'price_source' => 'NONE', 'reference_unit_id' => null];
        }

        return [
            'reference_price' => round((float) $unitCostBase * $conversion, 4),
            'price_source' => 'DERIVED',
            'reference_unit_id' => $unitId,
        ];
    }

    /** 1 unit_id = conversion_to_base * base unit. Returns null if unitId is neither the item's base unit nor a currently-open conversion. */
    private static function conversionToBase(PDO $pdo, int $itemId, int $unitId): ?float
    {
        $base = $pdo->prepare('SELECT base_unit_id FROM items WHERE id = :id');
        $base->execute(['id' => $itemId]);
        $baseUnitId = $base->fetchColumn();
        if ($baseUnitId !== false && (int) $baseUnitId === $unitId) {
            return 1.0;
        }

        $conv = $pdo->prepare(
            'SELECT conversion_to_base FROM item_unit_conversions
             WHERE item_id = :item_id AND unit_id = :unit_id AND valid_to IS NULL
             LIMIT 1'
        );
        $conv->execute(['item_id' => $itemId, 'unit_id' => $unitId]);
        $value = $conv->fetchColumn();
        return $value === false ? null : (float) $value;
    }
}
