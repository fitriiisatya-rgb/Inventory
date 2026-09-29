<?php
declare(strict_types=1);

/**
 * V1 provider, now bound to ONE specific committed import batch (design
 * review point 3): a session's snapshot must be traceable to exactly the
 * batch it was started against, not to whatever the rolling item_stock
 * table currently happens to hold (which could reflect an older batch
 * that has since been superseded, or could silently mask a SKU the
 * CURRENT batch never mentioned at all).
 *
 * Returns null when this exact batch has no committed row for the item —
 * that is MISSING_SYSTEM_STOCK, and callers (SessionService::preflight)
 * must treat it as a hard blocker, never default it to 0.
 */
final class ImportSystemStockProvider implements SystemStockProviderInterface
{
    public function __construct(private PDO $pdo, private int $batchId)
    {
    }

    public function getSystemStock(int $itemId, int $locationId): ?SystemStockResult
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.parsed_qty_base, r.parsed_unit_cost, r.unit_cost_source, b.committed_at
             FROM stock_import_rows r
             JOIN stock_import_batches b ON b.id = r.batch_id
             WHERE r.batch_id = ? AND r.item_id = ? AND r.status IN ('MATCHED','WARNING') AND r.committed = 1
             LIMIT 1"
        );
        $stmt->execute([$this->batchId, $itemId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return new SystemStockResult(
            (float) $row['parsed_qty_base'],
            $row['parsed_unit_cost'] !== null ? (float) $row['parsed_unit_cost'] : null,
            $row['unit_cost_source'] ?? 'NONE',
            $row['committed_at'],
            'IMPORT'
        );
    }
}
