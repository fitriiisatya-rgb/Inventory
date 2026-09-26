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

/** PHASE V2.13 (Karang Tengah): the target warehouse exists but is_active = 0 — not yet live. */
final class WarehouseInactiveException extends RuntimeException
{
    public function __construct(public readonly int $warehouseId)
    {
        parent::__construct("WAREHOUSE_INACTIVE: warehouse {$warehouseId} is not active and cannot be a source or destination of any stock-mutating operation");
    }
}

/**
 * PHASE V2.13.1: a generic, server-enforced activation lock — the target
 * warehouse has activation_locked = 1, so PUT /warehouses/{id} refuses an
 * is_active 0 -> 1 transition regardless of the caller's permissions.
 * Not Karang-Tengah-specific: any warehouse can carry this flag. Unlocking
 * is not exposed through any endpoint in this release. Error code:
 * WAREHOUSE_ACTIVATION_LOCKED.
 */
final class WarehouseActivationLockedException extends RuntimeException
{
    public function __construct(public readonly int $warehouseId)
    {
        parent::__construct('Gudang belum dapat diaktifkan karena proses cutover belum selesai.');
    }
}

/**
 * PHASE V2.13.2: a generic, server-enforced deletion lock — the target
 * warehouse has activation_locked = 1, so DELETE /warehouses/{id} refuses
 * to permanently remove it, regardless of the caller's permissions and
 * regardless of whether it currently has zero dependent rows (a locked
 * warehouse's zero-dependency state is exactly what makes it otherwise
 * deletable, which is the gap this closes). Not Karang-Tengah-specific:
 * any warehouse can carry this flag. Error code: WAREHOUSE_CUTOVER_LOCKED.
 */
final class WarehouseCutoverLockedException extends RuntimeException
{
    public function __construct(public readonly int $warehouseId)
    {
        parent::__construct('Gudang tidak dapat dihapus selama proses cutover masih terkunci.');
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

/**
 * POLICY CORRECTION: this item+warehouse is on the owner-approved
 * migration-negative whitelist (MigrationNegativeStockService) and its
 * current balance is already <= 0 — FIFO available quantity is clamped to
 * zero for consumption purposes, so OUT / TRANSFER_OUT / PRODUCTION_IN
 * (raw-material consumption) are all blocked, and NO further negative FIFO
 * batch is created, however Allow-Negative-Stock was set. The only way
 * past this is a real, audited Stock Opname or Stock Adjustment that
 * brings the balance back above zero. Error code:
 * NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT.
 */
final class NegativeMigrationStockRequiresAdjustmentException extends RuntimeException
{
    public function __construct(public readonly int $itemId, public readonly int $warehouseId, public readonly float $currentBalance)
    {
        parent::__construct(sprintf(
            'NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT: item %d at warehouse %d has a known migration-negative balance (%.6f) — resolve via Stock Opname or Stock Adjustment before posting OUT/TRANSFER_OUT/PRODUCTION_IN',
            $itemId,
            $warehouseId,
            $currentBalance
        ));
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
 * PHASE V2.5: OPENING is the authoritative go-live baseline — it is never
 * voidable through the generic correction flow, even by SUPERADMIN. If a
 * correction is ever genuinely required, it must go through a separate,
 * explicitly controlled cutover/opening correction procedure (not built by
 * this phase). Error code: OPENING_PROTECTED.
 */
final class OpeningProtectedException extends RuntimeException
{
    public function __construct(public readonly int $transactionId)
    {
        parent::__construct("OPENING_PROTECTED: transaction {$transactionId} is an OPENING record and cannot be voided/reversed through this flow");
    }
}

/**
 * PHASE V2.5: a genuinely new void request (different request_uuid) against
 * a transaction that is no longer POSTED (already VOID or REVERSED). Distinct
 * from the idempotent-replay path (a retried request_uuid returns success).
 * Error code: TRANSACTION_ALREADY_VOID.
 */
final class TransactionAlreadyVoidException extends RuntimeException
{
    public function __construct(public readonly int $transactionId, public readonly string $currentStatus)
    {
        parent::__construct("TRANSACTION_ALREADY_VOID: transaction {$transactionId} is already {$currentStatus}, cannot void it again");
    }
}

/**
 * PHASE V2.5: an IN (or positive ADJUSTMENT) transaction's created batch has
 * already been partially or fully consumed by a later OUT/TRANSFER_OUT/
 * PRODUCTION_IN/negative-ADJUSTMENT transaction — a naive quantity
 * subtraction would drive the batch negative and corrupt FIFO lineage.
 * The void is blocked rather than attempting an unsafe partial reversal;
 * the caller must void the downstream dependents first. Error code:
 * VOID_HAS_DOWNSTREAM_DEPENDENCIES.
 */
final class VoidHasDownstreamDependenciesException extends RuntimeException
{
    /** @param array<int, array{id:int, transaction_uuid:string, transaction_type:string, transaction_date:string, reference_no:?string}> $dependencies */
    public function __construct(public readonly int $transactionId, public readonly array $dependencies)
    {
        $ids = implode(', ', array_map(static fn ($d) => '#' . $d['id'], $dependencies));
        parent::__construct("VOID_HAS_DOWNSTREAM_DEPENDENCIES: transaction {$transactionId}'s stock has already been consumed by: {$ids} — void those first");
    }
}

/** Genuinely new attempt (different request_uuid) to reverse a transfer that is already REVERSED. Error code: TRANSFER_ALREADY_REVERSED. */
final class TransferAlreadyReversedException extends RuntimeException
{
    public function __construct(public readonly int $transferId)
    {
        parent::__construct("TRANSFER_ALREADY_REVERSED: transfer {$transferId} has already been reversed");
    }
}

/**
 * PHASE V2.5: a RECEIVED transfer's destination batch(es) have already been
 * consumed downstream (OUT, production, another transfer, adjustment, opname
 * correction) — an automatic reversal cannot be guaranteed correct, so it is
 * blocked rather than silently corrupting FIFO lineage. Error code:
 * TRANSFER_REVERSAL_HAS_DOWNSTREAM_DEPENDENCIES.
 */
final class TransferReversalHasDownstreamDependenciesException extends RuntimeException
{
    /** @param array<int, array{id:int, transaction_uuid:string, transaction_type:string, transaction_date:string, reference_no:?string}> $dependencies */
    public function __construct(public readonly int $transferId, public readonly array $dependencies)
    {
        $ids = implode(', ', array_map(static fn ($d) => '#' . $d['id'], $dependencies));
        parent::__construct("TRANSFER_REVERSAL_HAS_DOWNSTREAM_DEPENDENCIES: transfer {$transferId}'s destination stock has already been used by: {$ids}");
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
