<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Section 8: OUT/TRANSFER_OUT/PRODUCTION_IN would take stock below zero and no override was granted. */
final class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly float $requestedBaseQty,
        public readonly float $availableBaseQty
    ) {
        parent::__construct(sprintf(
            'STOCK_INSUFFICIENT: requested %.6f but only %.6f available',
            $requestedBaseQty,
            $availableBaseQty
        ));
    }
}

/** Section 9: unit cost falls outside the configured reference-price band. */
final class PriceAnomalyException extends RuntimeException
{
    public function __construct(public readonly float $ratio, public readonly float $reference)
    {
        parent::__construct(sprintf(
            'PRICE_ANOMALY: new unit cost is %.2fx the reference price (Rp%.4f)',
            $ratio,
            $reference
        ));
    }
}

/** Section 5: base unit / conversion structure change requested on an item that already has postings. */
final class ItemLockedException extends RuntimeException
{
    public function __construct(string $field)
    {
        parent::__construct("ITEM_LOCKED: {$field} cannot be changed after the item has posted transactions");
    }
}

final class ValidationException extends RuntimeException
{
    /** @param string[] $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('VALIDATION_FAILED: ' . implode('; ', $errors));
    }
}
