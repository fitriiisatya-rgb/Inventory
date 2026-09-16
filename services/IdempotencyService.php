<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/** Section 12: a repeated request_uuid (double-click Save, retried request) must never double-post. */
final class IdempotencyService
{
    public static function findTransaction(PDO $pdo, string $uuid): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM inventory_transactions WHERE transaction_uuid = :uuid');
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
