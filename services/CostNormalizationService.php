<?php
declare(strict_types=1);

namespace App\Services;

/**
 * PHASE G-DATA 2 / Section 8: pure math — normalizes a price expressed in
 * ANY unit down to a cost per the item's Global Base Unit. Never stores a
 * package price where a base-unit cost is expected; never guesses a
 * conversion factor it wasn't given.
 *
 * This mirrors exactly what FifoService::postIn already does internally
 * (unit_price_input / conversion_to_base) but is exposed as its own pure
 * function so opening-stock tooling (which posts base-unit cost directly,
 * per Phase G-DATA 2 Section 1's LOW-confidence policy) and tests can use
 * the same normalization math without needing a live transaction.
 */
final class CostNormalizationService
{
    private const MONEY_SCALE = 4;

    /**
     * @param float $priceInInputUnit price for ONE unit of $inputUnit (e.g. Rp580,930 per PCS)
     * @param float $conversionToBase 1 $inputUnit = $conversionToBase * base unit (e.g. 1 PCS = 15 KG)
     * @return float cost per ONE base unit (e.g. Rp38,728.6667 per KG)
     */
    public static function normalize(float $priceInInputUnit, float $conversionToBase): float
    {
        if ($conversionToBase <= 0) {
            throw new ValidationException(['conversion_to_base must be > 0']);
        }
        return round($priceInInputUnit / $conversionToBase, self::MONEY_SCALE);
    }

    /**
     * Verifies total monetary value is preserved by normalization: qty (in
     * input unit) x price (per input unit) must equal base_qty x cost_base,
     * within rounding tolerance. Used by tests, not by the posting path
     * itself (FifoService already guarantees this algebraically), but kept
     * here so the guarantee has an explicit, independently-checkable form.
     */
    public static function valuePreserved(
        float $qtyInputUnit,
        float $priceInInputUnit,
        float $conversionToBase,
        float $tolerance = 0.01
    ): bool {
        $originalValue = round($qtyInputUnit * $priceInInputUnit, self::MONEY_SCALE);
        $baseQty = round($qtyInputUnit * $conversionToBase, 6);
        $costBase = self::normalize($priceInInputUnit, $conversionToBase);
        $normalizedValue = round($baseQty * $costBase, self::MONEY_SCALE);
        return abs($originalValue - $normalizedValue) <= $tolerance;
    }
}
