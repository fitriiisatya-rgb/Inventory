<?php
declare(strict_types=1);

/** V1 provider: reads whatever was last committed via Import Stok Sistem. */
final class ImportSystemStockProvider implements SystemStockProviderInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function getSystemStock(int $itemId, int $locationId): ?SystemStockResult
    {
        $stmt = $this->pdo->prepare(
            'SELECT system_qty, unit_cost, updated_at FROM item_stock WHERE item_id = ? AND location_id = ? LIMIT 1'
        );
        $stmt->execute([$itemId, $locationId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return new SystemStockResult(
            (float) $row['system_qty'],
            (float) $row['unit_cost'],
            $row['updated_at'],
            'IMPORT'
        );
    }
}
