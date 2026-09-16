<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/** Section 9: block silent posting of a wildly-off unit cost. */
final class PriceAnomalyService
{
    public static function evaluate(
        PDO $pdo,
        int $itemId,
        float $newUnitCostBase,
        float $highMultiplier,
        float $lowMultiplier
    ): array {
        $stmt = $pdo->prepare(
            'SELECT unit_cost_base FROM item_price_history
             WHERE item_id = :item_id
             ORDER BY effective_date DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['item_id' => $itemId]);
        $reference = $stmt->fetchColumn();

        if ($reference === false || (float) $reference <= 0) {
            return ['is_anomaly' => false, 'ratio' => null, 'reference' => null];
        }

        $reference = (float) $reference;
        $ratio = $newUnitCostBase / $reference;
        $isAnomaly = $ratio > $highMultiplier || $ratio < $lowMultiplier;

        return ['is_anomaly' => $isAnomaly, 'ratio' => $ratio, 'reference' => $reference];
    }

    public static function recordPrice(
        PDO $pdo,
        int $itemId,
        ?int $supplierId,
        int $unitId,
        float $pricePerUnit,
        float $unitCostBase,
        string $effectiveDate,
        ?int $sourceTransactionLineId
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO item_price_history
                (item_id, supplier_id, unit_id, price_per_unit, unit_cost_base, effective_date, source_transaction_line_id, created_at)
             VALUES (:item_id, :supplier_id, :unit_id, :price, :cost_base, :eff_date, :src_line, :created_at)'
        );
        $stmt->execute([
            'item_id'    => $itemId,
            'supplier_id'=> $supplierId,
            'unit_id'    => $unitId,
            'price'      => $pricePerUnit,
            'cost_base'  => $unitCostBase,
            'eff_date'   => $effectiveDate,
            'src_line'   => $sourceTransactionLineId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
