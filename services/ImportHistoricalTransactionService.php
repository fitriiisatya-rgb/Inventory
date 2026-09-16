<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE E2 / Section 15: historical transactions are for reporting/audit
 * ONLY — is_historical_import=1, inventory_effect=0 on every row this
 * creates. This class NEVER calls FifoService and NEVER touches
 * inventory_batches: opening stock (ImportOpeningStockService) is the
 * authoritative baseline, and historical rows must not be replayed to
 * derive or adjust it.
 *
 * Expected CSV header (see templates/historical_transactions.csv):
 * transaction_date,transaction_type,warehouse_code,sku,input_qty,input_unit,
 * unit_price_input,supplier_code,division_code,reference_no,notes
 */
final class ImportHistoricalTransactionService
{
    private const ALLOWED_TYPES = ['IN', 'OUT', 'TRANSFER_OUT', 'TRANSFER_IN', 'ADJUSTMENT', 'OPNAME', 'PRODUCTION_IN', 'PRODUCTION_OUT'];

    public static function stage(PDO $pdo, string $csvPath, string $fileName, int $uploadedBy): int
    {
        $rows = self::readCsv($csvPath);

        $batchStmt = $pdo->prepare(
            'INSERT INTO import_batches (import_type, file_name, status, total_rows, uploaded_by, uploaded_at)
             VALUES (\'HISTORICAL_TRANSACTION\', :file_name, \'STAGED\', :total, :uploaded_by, :uploaded_at)'
        );
        $batchStmt->execute(['file_name' => $fileName, 'total' => count($rows), 'uploaded_by' => $uploadedBy, 'uploaded_at' => date('Y-m-d H:i:s')]);
        $importBatchId = (int) $pdo->lastInsertId();

        $counts = ['VALID' => 0, 'WARNING' => 0, 'ERROR' => 0];
        $rowStmt = $pdo->prepare(
            'INSERT INTO import_rows (import_batch_id, row_no, raw_data, row_status, messages)
             VALUES (:batch_id, :row_no, :raw, :status, :messages)'
        );

        foreach ($rows as $i => $row) {
            [$status, $messages] = self::validateRow($pdo, $row);
            $counts[$status]++;
            $rowStmt->execute([
                'batch_id' => $importBatchId, 'row_no' => $i + 1,
                'raw' => json_encode($row, JSON_UNESCAPED_UNICODE),
                'status' => $status, 'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $pdo->prepare(
            'UPDATE import_batches SET status = \'VALIDATED\', valid_rows = :v, warning_rows = :w, error_rows = :e WHERE id = :id'
        )->execute(['v' => $counts['VALID'], 'w' => $counts['WARNING'], 'e' => $counts['ERROR'], 'id' => $importBatchId]);

        return $importBatchId;
    }

    /** @return array{0:string,1:string[]} */
    private static function validateRow(PDO $pdo, array $row): array
    {
        $messages = [];

        $type = strtoupper(trim((string) ($row['transaction_type'] ?? '')));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return ['ERROR', ["transaction_type must be one of: " . implode(',', self::ALLOWED_TYPES)]];
        }

        $whCode = trim((string) ($row['warehouse_code'] ?? ''));
        $wh = $pdo->prepare('SELECT id FROM warehouses WHERE code = :code');
        $wh->execute(['code' => $whCode]);
        if ($wh->fetchColumn() === false) {
            return ['ERROR', ["unknown warehouse_code: {$whCode}"]];
        }

        $sku = trim((string) ($row['sku'] ?? ''));
        $item = $pdo->prepare('SELECT id, base_unit_id FROM items WHERE sku = :sku');
        $item->execute(['sku' => $sku]);
        $itemRow = $item->fetch();
        if (!$itemRow) {
            return ['ERROR', ["unknown SKU: {$sku}"]];
        }

        $qty = $row['input_qty'] ?? '';
        if ($qty === '' || (float) $qty == 0.0) {
            return ['ERROR', ['input_qty must not be zero']];
        }

        $unitCode = strtoupper(trim((string) ($row['input_unit'] ?? '')));
        $unit = $pdo->prepare('SELECT id FROM units WHERE code = :code');
        $unit->execute(['code' => $unitCode]);
        $unitId = $unit->fetchColumn();
        if ($unitId === false) {
            return ['ERROR', ["unknown input_unit: {$unitCode}"]];
        }

        $conversion = UnitConversionService::getActiveConversion($pdo, (int) $itemRow['id'], (int) $unitId, (string) ($row['transaction_date'] ?? date('Y-m-d')));
        if ($conversion === null) {
            $messages[] = 'no unit conversion found for this item/unit as of transaction_date — base_qty will be recorded 1:1 without conversion';
            return ['WARNING', $messages];
        }

        return ['VALID', $messages];
    }

    public static function commit(PDO $pdo, int $importBatchId, int $committedBy): array
    {
        $batch = $pdo->prepare("SELECT * FROM import_batches WHERE id = :id AND import_type = 'HISTORICAL_TRANSACTION'");
        $batch->execute(['id' => $importBatchId]);
        $batch = $batch->fetch();
        if (!$batch) {
            throw new NotFoundException('import batch not found');
        }
        if ((int) $batch['error_rows'] > 0) {
            throw new ImportValidationException(['import rejected: batch has ' . $batch['error_rows'] . ' ERROR row(s)']);
        }

        $rowsStmt = $pdo->prepare(
            "SELECT * FROM import_rows WHERE import_batch_id = :id AND row_status IN ('VALID','WARNING') ORDER BY row_no"
        );
        $rowsStmt->execute(['id' => $importBatchId]);
        $rows = $rowsStmt->fetchAll();

        $created = 0;
        foreach ($rows as $importRow) {
            $data = json_decode($importRow['raw_data'], true);
            self::insertHistoricalRow($pdo, $data, $importBatchId, $importRow['row_no'], $committedBy);
            $pdo->prepare('UPDATE import_rows SET created_entity_id = :id2 WHERE id = :id')
                ->execute(['id2' => null, 'id' => $importRow['id']]);
            $created++;
        }

        $pdo->prepare('UPDATE import_batches SET status = \'COMMITTED\', committed_by = :by, committed_at = :now WHERE id = :id')
            ->execute(['by' => $committedBy, 'now' => date('Y-m-d H:i:s'), 'id' => $importBatchId]);

        return ['imported' => $created];
    }

    private static function insertHistoricalRow(PDO $pdo, array $data, int $importBatchId, int $rowNo, int $committedBy): void
    {
        $whStmt = $pdo->prepare('SELECT id FROM warehouses WHERE code = :code');
        $whStmt->execute(['code' => trim($data['warehouse_code'])]);
        $warehouseId = (int) $whStmt->fetchColumn();

        $itemStmt = $pdo->prepare('SELECT id, base_unit_id, name FROM items WHERE sku = :sku');
        $itemStmt->execute(['sku' => trim($data['sku'])]);
        $item = $itemStmt->fetch();
        $itemId = (int) $item['id'];

        $unitStmt = $pdo->prepare('SELECT id FROM units WHERE code = :code');
        $unitStmt->execute(['code' => strtoupper(trim($data['input_unit']))]);
        $unitId = (int) $unitStmt->fetchColumn();

        $conversion = UnitConversionService::getActiveConversion($pdo, $itemId, $unitId, $data['transaction_date']);
        $factor = $conversion ? (float) $conversion['conversion_to_base'] : 1.0;
        $inputQty = (float) $data['input_qty'];
        $baseQty = round($inputQty * $factor, 6);
        $unitPrice = (float) ($data['unit_price_input'] ?? 0);
        $unitCostBase = $factor > 0 ? round($unitPrice / $factor, 4) : 0;

        $supplierId = null;
        if (!empty($data['supplier_code'])) {
            $supplierStmt = $pdo->prepare('SELECT id FROM suppliers WHERE code = :code');
            $supplierStmt->execute(['code' => trim($data['supplier_code'])]);
            $supplierId = $supplierStmt->fetchColumn() ?: null;
        }
        $divisionId = null;
        if (!empty($data['division_code'])) {
            $divisionStmt = $pdo->prepare('SELECT id FROM divisions WHERE code = :code');
            $divisionStmt->execute(['code' => trim($data['division_code'])]);
            $divisionId = $divisionStmt->fetchColumn() ?: null;
        }

        $now = date('Y-m-d H:i:s');
        $txStmt = $pdo->prepare(
            'INSERT INTO inventory_transactions
                (transaction_uuid, transaction_type, transaction_date, posting_date, warehouse_id, supplier_id, division_id,
                 reference_no, status, is_historical_import, inventory_effect, created_by, created_at)
             VALUES (:uuid, :type, :tx_date, :post_date, :wh, :supplier, :division, :ref, \'POSTED\', 1, 0, :created_by, :now)'
        );
        $txStmt->execute([
            'uuid' => 'HIST-' . $importBatchId . '-' . $rowNo, 'type' => strtoupper(trim($data['transaction_type'])),
            'tx_date' => $data['transaction_date'], 'post_date' => $now, 'wh' => $warehouseId,
            'supplier' => $supplierId, 'division' => $divisionId, 'ref' => $data['reference_no'] ?? null,
            'created_by' => $committedBy, 'now' => $now,
        ]);
        $transactionId = (int) $pdo->lastInsertId();

        $lineStmt = $pdo->prepare(
            'INSERT INTO inventory_transaction_lines
                (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id,
                 conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id, notes)
             VALUES (:tx_id, 1, :item_id, :item_name, :input_qty, :unit_id, :factor, :base_qty, :price, :cost, :subtotal, :wh, :notes)'
        );
        $lineStmt->execute([
            'tx_id' => $transactionId, 'item_id' => $itemId, 'item_name' => $item['name'],
            'input_qty' => $inputQty, 'unit_id' => $unitId, 'factor' => $factor, 'base_qty' => $baseQty,
            'price' => $unitPrice, 'cost' => $unitCostBase, 'subtotal' => round($inputQty * $unitPrice, 4),
            'wh' => $warehouseId, 'notes' => $data['notes'] ?? null,
        ]);

        // Deliberately no inventory_batches row, no fifo_allocations — historical import must not affect stock.
    }

    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new ValidationException(["cannot open file: {$path}"]);
        }
        $header = fgetcsv($handle);
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === 1 && $line[0] === null) {
                continue;
            }
            $rows[] = array_combine($header, array_pad($line, count($header), ''));
        }
        fclose($handle);
        return $rows;
    }
}
