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

/** Section 6: transaction_date falls inside a LOCKED book-closing period. */
final class PeriodLockedException extends RuntimeException
{
    public function __construct(public readonly string $transactionDate)
    {
        parent::__construct("PERIOD_LOCKED: {$transactionDate} falls inside a closed accounting period");
    }
}

/** Section 2 (Stock Opname): the target warehouse has an active opname session blocking movement. */
final class WarehouseLockedException extends RuntimeException
{
    public function __construct(public readonly int $warehouseId)
    {
        parent::__construct("WAREHOUSE_LOCKED: warehouse {$warehouseId} has an active stock opname session");
    }
}

/** PHASE C3: too many recent failed logins for this username/IP. */
final class RateLimitedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("RATE_LIMITED: too many failed login attempts, try again in {$retryAfterSeconds}s");
    }
}

/** Stock adjustment IN with no override cost and no reliable prior cost to fall back on. */
final class CostRequiredException extends RuntimeException
{
    public function __construct(public readonly int $itemId)
    {
        parent::__construct("COST_REQUIRED: item {$itemId} has no reliable cost — supply an explicit cost to post this adjustment");
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

/**
 * Shared "does this input array have everything it claims to" guard, used
 * by every service method that accepts a raw request payload — so a
 * malformed/incomplete client request fails as a clean VALIDATION_FAILED
 * instead of a PHP "Undefined array key" warning surfacing as a generic 500.
 */
function assert_required_fields(array $p, array $required): void
{
    $missing = array_values(array_filter($required, fn ($key) => !array_key_exists($key, $p) || $p[$key] === null || $p[$key] === ''));
    if (!empty($missing)) {
        throw new ValidationException(['missing required field(s): ' . implode(', ', $missing)]);
    }
}
