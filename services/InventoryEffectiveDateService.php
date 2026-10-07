<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * The REPORTING date of an inventory transaction.
 *
 * inventory_transactions.transaction_date is what the posting workflow wrote (for a Stock Opname adjustment: the session date at 23:59:59) and posting_date / created_at
 * are the real posting time. Neither is ever changed by this feature. When the business decides that a posted document belongs to an EARLIER reporting period — e.g. the
 * Stock Opname sessions 11 / 12 whose inventory cutoff is 30 Sep 2026 although they were counted and posted in October — a row in inventory_effective_dates says so:
 *
 *     effective_at  = the instant the transaction counts for in every period report (September closing → October opening)
 *     original_transaction_date = what transaction_date was when the row was written (audit proof that nothing was edited)
 *
 * Reports use col($pdo) wherever they used `t.transaction_date` for opening / movement / closing: with NO override rows it returns the plain column (zero behaviour or
 * performance change — the feature is dormant until the controlled script writes the first row); with overrides it returns COALESCE((SELECT effective_at …), transaction_date).
 * Transaction history, audit trail and traces keep showing the original dates. The table is created / filled / emptied only by scripts/rv3/period_cutoff.php (dry-run by default).
 */
final class InventoryEffectiveDateService
{
    public const TABLE = 'inventory_effective_dates';

    /** @var array<int,bool> per connection (object id) */
    private static array $active = [];

    /** true when the table exists AND holds at least one override row (otherwise nothing changes anywhere). */
    public static function active(PDO $pdo): bool
    {
        $k = spl_object_id($pdo);
        if (!isset(self::$active[$k])) {
            try {
                self::$active[$k] = $pdo->query('SELECT 1 FROM ' . self::TABLE . ' LIMIT 1')->fetchColumn() !== false;
            } catch (\PDOException) {
                self::$active[$k] = false;   // table not installed yet
            }
        }
        return self::$active[$k];
    }

    /** SQL expression for the reporting date of the inventory_transactions row aliased $alias. */
    public static function col(PDO $pdo, string $alias = 't'): string
    {
        if (!self::active($pdo)) {
            return "{$alias}.transaction_date";
        }
        return 'COALESCE((SELECT ied.effective_at FROM ' . self::TABLE . " ied WHERE ied.transaction_id = {$alias}.id), {$alias}.transaction_date)";
    }

    /** The instant a cutoff DATE counts for: the end of that day (so it is in that day's closing, never in the next day's opening movements). */
    public static function cutoffInstant(string $date): string
    {
        return $date . ' 23:59:59';
    }

    /** Forget the cached state (after the table / rows were created or removed in the same process — scripts, tests). */
    public static function resetCache(): void
    {
        self::$active = [];
    }
}
