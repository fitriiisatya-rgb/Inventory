<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE C2 Section 6: server-side period lock. Every posting endpoint
 * (transactions in/out, transfers, production, adjustments, opname) must
 * call assertNotLocked() before writing anything — the old system trusted
 * client-side date checks, which is exactly the gap Tutup Buku's "delete
 * the period" behavior papered over instead of actually closing.
 */
final class PeriodLockService
{
    public static function isLocked(PDO $pdo, string $transactionDate): bool
    {
        $date = substr($transactionDate, 0, 10); // DATE portion of a DATETIME string
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM book_closings
             WHERE status = 'LOCKED' AND :date BETWEEN period_start AND period_end"
        );
        $stmt->execute(['date' => $date]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    public static function assertNotLocked(PDO $pdo, string $transactionDate): void
    {
        if (self::isLocked($pdo, $transactionDate)) {
            throw new PeriodLockedException($transactionDate);
        }
    }
}
