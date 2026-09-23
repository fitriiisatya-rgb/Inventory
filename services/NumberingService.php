<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.11A — deterministic, concurrency-safe document numbering
 * (DO-YYYYMMDD-####, later reused for INV-YYYYMMDD-#### in Phase V2.11B).
 *
 * Backed by document_number_sequences, one counter row per (doc_type,
 * date). The increment uses MySQL's `INSERT ... ON DUPLICATE KEY UPDATE
 * last_seq = LAST_INSERT_ID(last_seq + 1)` idiom: the PRIMARY KEY row lock
 * on (doc_type, date_key) serializes concurrent callers, and
 * LAST_INSERT_ID(expr) makes PDO::lastInsertId() return the *computed*
 * new value rather than an actual auto-increment id — so two concurrent
 * requests for the same day can never receive the same number, without
 * needing a separate SELECT ... FOR UPDATE round trip.
 */
final class NumberingService
{
    public static function next(PDO $pdo, string $docType, string $date): string
    {
        $dateKey = date('Ymd', strtotime($date));

        // Both branches must explicitly call LAST_INSERT_ID(): a plain INSERT
        // on a table with no AUTO_INCREMENT column never touches the
        // session's last-insert-id by itself, so the first-ever row for a
        // given (doc_type, date_key) needs LAST_INSERT_ID(1) on the INSERT
        // side too — otherwise only the UPDATE branch would ever report a
        // correct value and the very first number of each day would come
        // back as whatever unrelated auto-increment id last happened on
        // this connection.
        $pdo->prepare(
            'INSERT INTO document_number_sequences (doc_type, date_key, last_seq)
             VALUES (:doc_type, :date_key, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)'
        )->execute(['doc_type' => $docType, 'date_key' => $dateKey]);

        $seq = (int) $pdo->lastInsertId();

        return sprintf('%s-%s-%04d', $docType, $dateKey, $seq);
    }
}
