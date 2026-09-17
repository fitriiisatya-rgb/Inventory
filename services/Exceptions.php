<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Section 8: OUT/TRANSFER_OUT/PRODUCTION_IN would take stock below zero and no override was granted. Error code: INSUFFICIENT_STOCK. */
final class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly float $requestedBaseQty,
        public readonly float $availableBaseQty
    ) {
        parent::__construct(sprintf(
            'INSUFFICIENT_STOCK: requested %.6f but only %.6f available',
            $requestedBaseQty,
            $availableBaseQty
        ));
    }
}

/** A stock adjustment/correction would take stock below zero and no override was granted. Error code: NEGATIVE_STOCK (distinct from a plain OUT transaction's INSUFFICIENT_STOCK). */
final class NegativeStockException extends RuntimeException
{
    public function __construct(
        public readonly float $requestedBaseQty,
        public readonly float $availableBaseQty
    ) {
        parent::__construct(sprintf(
            'NEGATIVE_STOCK: adjustment of %.6f would take stock below zero (only %.6f available)',
            $requestedBaseQty,
            $availableBaseQty
        ));
    }
}

/** A requested entity (by id, or by natural key) does not exist. Error code: NOT_FOUND. */
final class NotFoundException extends RuntimeException
{
    public function __construct(string $what)
    {
        parent::__construct("NOT_FOUND: {$what}");
    }
}

/** Genuinely new attempt (different request_uuid) to receive a transfer that is already RECEIVED. */
final class TransferAlreadyReceivedException extends RuntimeException
{
    public function __construct(public readonly int $transferId)
    {
        parent::__construct("TRANSFER_ALREADY_RECEIVED: transfer {$transferId} has already been received");
    }
}

/** Genuinely new attempt (different request_uuid) to cancel a transfer that is already CANCELLED. */
final class TransferAlreadyCancelledException extends RuntimeException
{
    public function __construct(public readonly int $transferId)
    {
        parent::__construct("TRANSFER_ALREADY_CANCELLED: transfer {$transferId} has already been cancelled");
    }
}

/**
 * Two concurrent requests raced with the SAME idempotency key and both
 * passed the "does this uuid already exist" pre-check before either had
 * committed — the DB's own UNIQUE constraint on transaction_uuid is the
 * real backstop, and its violation is translated to this. Error code:
 * DUPLICATE_REQUEST (409). This is different from the normal idempotent-
 * replay path (a uuid found already committed returns success, not this).
 */
final class DuplicateRequestException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('DUPLICATE_REQUEST: this request is already being processed or was just processed');
    }
}

/** Import commit refused because the staged batch has ERROR rows. Error code: IMPORT_VALIDATION_FAILED. */
final class ImportValidationException extends RuntimeException
{
    /** @param string[] $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('IMPORT_VALIDATION_FAILED: ' . implode('; ', $errors));
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

/**
 * PHASE G-DATA 2: a transaction was posted in a unit that has no active,
 * approved conversion for this item as of the transaction date. The
 * caller must re-post in the item's base unit (always available — every
 * item gets an identity base-unit conversion at creation) or in another
 * unit that already has an approved conversion. Never silently falls
 * back to a guessed factor. Error code: UNIT_CONVERSION_NOT_APPROVED.
 */
final class UnitConversionNotApprovedException extends RuntimeException
{
    public function __construct(public readonly int $itemId, public readonly int $unitId)
    {
        parent::__construct(
            "UNIT_CONVERSION_NOT_APPROVED: item {$itemId} has no active, approved conversion for unit {$unitId} — " .
            "post in the item's base unit or another approved unit instead"
        );
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
