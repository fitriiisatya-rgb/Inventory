<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE C2 Section 4 (Produksi/Racik). BEGIN -> consume raw materials FIFO
 * -> compute actual consumed cost -> create one finished-good batch at
 * (total input cost / output qty) -> COMMIT. Single output only, matching
 * what the existing system supports (Phase A analysis: getRacikKonsumsi /
 * getProduksiMasuk track one output per production event) — multi-output
 * allocation is intentionally not invented here.
 */
final class ProductionService
{
    public static function create(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['production_uuid', 'warehouse_id', 'production_date', 'created_by', 'inputs', 'output']);

        $existing = self::findByUuid($pdo, $p['production_uuid']);
        if ($existing) {
            return ['success' => true, 'idempotent_replay' => true, 'production_id' => (int) $existing['id']];
        }

        if (empty($p['inputs'])) {
            throw new ValidationException(['at least one raw material input is required']);
        }
        if (empty($p['output']) || empty($p['output']['item_id'])) {
            throw new ValidationException(['exactly one output item is required']);
        }

        PeriodLockService::assertNotLocked($pdo, $p['production_date']);

        $now = date('Y-m-d H:i:s');
        $header = $pdo->prepare(
            'INSERT INTO production_headers (production_uuid, warehouse_id, division_id, production_date, status, notes, created_by, created_at)
             VALUES (:uuid, :wh, :division, :date, \'POSTED\', :notes, :created_by, :now)'
        );
        $header->execute([
            'uuid' => $p['production_uuid'], 'wh' => $p['warehouse_id'], 'division' => $p['division_id'] ?? null,
            'date' => $p['production_date'], 'notes' => $p['notes'] ?? null, 'created_by' => $p['created_by'], 'now' => $now,
        ]);
        $productionId = (int) $pdo->lastInsertId();

        $totalInputCost = 0.0;
        $inputRows = [];
        foreach ($p['inputs'] as $input) {
            $outResult = FifoService::postOut($pdo, [
                'transaction_uuid' => $p['production_uuid'] . ':IN:' . $input['item_id'],
                'item_id' => $input['item_id'],
                'warehouse_id' => $p['warehouse_id'],
                'input_qty' => $input['input_qty'],
                'input_unit_id' => $input['input_unit_id'],
                'transaction_type' => 'PRODUCTION_IN', // raw material consumed BY production
                'transaction_date' => $p['production_date'],
                'reference_no' => "PRODUCTION-{$productionId}",
                'created_by' => $p['created_by'],
                'username' => $p['username'] ?? 'system',
                'allow_negative_stock' => $p['allow_negative_stock'] ?? false,
            ]);
            $totalInputCost += $outResult['total_cost'];

            $inputStmt = $pdo->prepare(
                'INSERT INTO production_inputs (production_id, item_id, qty_base, unit_cost_base, transaction_line_id)
                 VALUES (:pid, :item_id, :qty, :cost, :line_id)'
            );
            $inputStmt->execute([
                'pid' => $productionId, 'item_id' => $input['item_id'],
                'qty' => $outResult['base_qty'], 'cost' => $outResult['unit_cost_base'], 'line_id' => $outResult['line_id'],
            ]);
            $inputRows[] = ['item_id' => $input['item_id'], 'base_qty' => $outResult['base_qty'], 'cost' => $outResult['total_cost']];
        }

        $totalInputCost = round($totalInputCost, 4);

        $outputItemId = (int) $p['output']['item_id'];
        $outputBaseUnitId = self::baseUnitId($pdo, $outputItemId);
        $outputConversion = UnitConversionService::getActiveConversion($pdo, $outputItemId, $p['output']['output_unit_id'] ?? $outputBaseUnitId, $p['production_date']);
        $factor = $outputConversion ? (float) $outputConversion['conversion_to_base'] : 1.0;
        $outputBaseQty = round((float) $p['output']['output_qty'] * $factor, 6);

        if ($outputBaseQty <= 0) {
            throw new ValidationException(['output_qty must be > 0']);
        }

        $outputUnitCostBase = round($totalInputCost / $outputBaseQty, 4);

        $inResult = FifoService::postIn($pdo, [
            'transaction_uuid' => $p['production_uuid'] . ':OUT:' . $outputItemId,
            'item_id' => $outputItemId,
            'warehouse_id' => $p['warehouse_id'],
            'input_qty' => $outputBaseQty,
            'input_unit_id' => $outputBaseUnitId, // base unit, factor 1 — unit_price_input below IS the final unit_cost_base
            'unit_price_input' => $outputUnitCostBase,
            'transaction_type' => 'PRODUCTION_OUT',
            'transaction_date' => $p['production_date'],
            'reference_no' => "PRODUCTION-{$productionId}",
            'created_by' => $p['created_by'],
            'username' => $p['username'] ?? 'system',
            'anomaly_approved_by' => $p['created_by'], // derived cost, not a purchase price — anomaly check does not apply
        ]);

        $outputStmt = $pdo->prepare(
            'INSERT INTO production_outputs (production_id, item_id, qty_base, unit_cost_base, transaction_line_id)
             VALUES (:pid, :item_id, :qty, :cost, :line_id)'
        );
        $outputStmt->execute([
            'pid' => $productionId, 'item_id' => $outputItemId,
            'qty' => $outputBaseQty, 'cost' => $outputUnitCostBase, 'line_id' => $inResult['line_id'],
        ]);

        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'PRODUCTION_POST', 'production_headers', $productionId,
            null, ['total_input_cost' => $totalInputCost, 'output_qty_base' => $outputBaseQty, 'output_unit_cost_base' => $outputUnitCostBase], null
        );

        return [
            'success' => true, 'production_id' => $productionId,
            'total_input_cost' => $totalInputCost, 'inputs' => $inputRows,
            'output_qty_base' => $outputBaseQty, 'output_unit_cost_base' => $outputUnitCostBase,
        ];
    }

    public static function get(PDO $pdo, int $productionId): array
    {
        $header = $pdo->prepare('SELECT * FROM production_headers WHERE id = :id');
        $header->execute(['id' => $productionId]);
        $header = $header->fetch();
        if (!$header) {
            throw new ValidationException(['production not found']);
        }
        $inputs = $pdo->prepare('SELECT * FROM production_inputs WHERE production_id = :id');
        $inputs->execute(['id' => $productionId]);
        $outputs = $pdo->prepare('SELECT * FROM production_outputs WHERE production_id = :id');
        $outputs->execute(['id' => $productionId]);
        $header['inputs'] = $inputs->fetchAll();
        $header['outputs'] = $outputs->fetchAll();
        return $header;
    }

    private static function findByUuid(PDO $pdo, string $uuid): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM production_headers WHERE production_uuid = :uuid');
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function baseUnitId(PDO $pdo, int $itemId): int
    {
        $stmt = $pdo->prepare('SELECT base_unit_id FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        return (int) $stmt->fetchColumn();
    }
}
