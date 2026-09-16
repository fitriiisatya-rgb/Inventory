<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE E2 / Section 14: Opening Stock becomes the AUTHORITATIVE inventory
 * baseline — every committed line creates a real inventory_batches row via
 * FifoService::postIn (transaction_type OPENING), not a bypassed insert,
 * so the ledger (Section 8) can show "Opening 100" as a real line like any
 * other movement. Uses the dedicated stock_openings/stock_opening_lines
 * staging tables (schema.sql Section 5) rather than the generic
 * import_rows table, since each line needs typed columns (qty, cost,
 * expiry, batch_reference) the generic JSON-blob staging doesn't give you
 * for free validation.
 *
 * Expected CSV header (see templates/opening_stock.csv):
 * cutoff_date,warehouse_code,sku,quantity_base,unit_cost_base,expired_date,batch_reference
 */
final class ImportOpeningStockService
{
    public static function stage(PDO $pdo, string $csvPath, string $fileName, int $createdBy): int
    {
        $rows = self::readCsv($csvPath);
        if (empty($rows)) {
            throw new ValidationException(['file has no data rows']);
        }

        $cutoffDate = trim((string) $rows[0]['cutoff_date']);

        $headerStmt = $pdo->prepare(
            'INSERT INTO stock_openings (cutoff_date, description, status, created_by, created_at)
             VALUES (:cutoff, :description, \'DRAFT\', :created_by, :now)'
        );
        $headerStmt->execute(['cutoff' => $cutoffDate, 'description' => $fileName, 'created_by' => $createdBy, 'now' => date('Y-m-d H:i:s')]);
        $openingId = (int) $pdo->lastInsertId();

        $lineStmt = $pdo->prepare(
            'INSERT INTO stock_opening_lines
                (stock_opening_id, item_id, warehouse_id, qty_base, unit_cost_base, expiry_date, batch_reference, row_status, row_messages)
             VALUES (:opening_id, :item_id, :warehouse_id, :qty, :cost, :expiry, :batch_ref, :status, :messages)'
        );

        foreach ($rows as $row) {
            [$status, $messages, $itemId, $warehouseId] = self::validateRow($pdo, $row);
            $lineStmt->execute([
                'opening_id' => $openingId, 'item_id' => $itemId, 'warehouse_id' => $warehouseId,
                'qty' => $row['quantity_base'] !== '' ? (float) $row['quantity_base'] : 0,
                'cost' => $row['unit_cost_base'] !== '' ? (float) $row['unit_cost_base'] : 0,
                'expiry' => $row['expired_date'] !== '' ? $row['expired_date'] : null,
                'batch_ref' => $row['batch_reference'] ?? null,
                'status' => $status, 'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $openingId;
    }

    /** @return array{0:string,1:string[],2:?int,3:?int} */
    private static function validateRow(PDO $pdo, array $row): array
    {
        $messages = [];

        $whCode = trim((string) ($row['warehouse_code'] ?? ''));
        $whStmt = $pdo->prepare('SELECT id FROM warehouses WHERE code = :code');
        $whStmt->execute(['code' => $whCode]);
        $warehouseId = $whStmt->fetchColumn();
        if ($warehouseId === false) {
            return ['ERROR', ["unknown warehouse_code: {$whCode}"], null, null];
        }

        $sku = trim((string) ($row['sku'] ?? ''));
        $itemStmt = $pdo->prepare('SELECT id FROM items WHERE sku = :sku');
        $itemStmt->execute(['sku' => $sku]);
        $itemId = $itemStmt->fetchColumn();
        if ($itemId === false) {
            return ['ERROR', ["unknown SKU: {$sku}"], null, (int) $warehouseId];
        }

        $qty = $row['quantity_base'] ?? '';
        if ($qty === '' || !((float) $qty > 0)) {
            return ['ERROR', ['quantity_base must be > 0'], (int) $itemId, (int) $warehouseId];
        }
        if ((float) $qty < 0) {
            return ['ERROR', ['negative opening quantity is not allowed'], (int) $itemId, (int) $warehouseId];
        }

        $cost = $row['unit_cost_base'] ?? '';
        if ($cost === '' || (float) $cost < 0) {
            return ['ERROR', ['unit_cost_base must be >= 0'], (int) $itemId, (int) $warehouseId];
        }
        if ((float) $cost === 0.0) {
            $messages[] = 'zero cost opening — verify this is intentional';
            return ['WARNING', $messages, (int) $itemId, (int) $warehouseId];
        }

        return ['VALID', $messages, (int) $itemId, (int) $warehouseId];
    }

    public static function commit(PDO $pdo, int $openingId, int $committedBy): array
    {
        $opening = $pdo->prepare('SELECT * FROM stock_openings WHERE id = :id');
        $opening->execute(['id' => $openingId]);
        $opening = $opening->fetch();
        if (!$opening) {
            throw new NotFoundException('stock opening batch not found');
        }

        $errorCount = $pdo->prepare("SELECT COUNT(*) FROM stock_opening_lines WHERE stock_opening_id = :id AND row_status = 'ERROR'");
        $errorCount->execute(['id' => $openingId]);
        if ((int) $errorCount->fetchColumn() > 0) {
            throw new ImportValidationException(['import rejected: one or more ERROR rows in this opening stock batch']);
        }

        $lines = $pdo->prepare("SELECT * FROM stock_opening_lines WHERE stock_opening_id = :id AND row_status IN ('VALID','WARNING')");
        $lines->execute(['id' => $openingId]);
        $lines = $lines->fetchAll();

        $created = 0;
        foreach ($lines as $line) {
            $baseUnitId = self::baseUnitId($pdo, (int) $line['item_id']);
            $result = FifoService::postIn($pdo, [
                'transaction_uuid' => 'OPENING-' . $openingId . '-' . $line['id'],
                'item_id' => $line['item_id'], 'warehouse_id' => $line['warehouse_id'],
                'input_qty' => $line['qty_base'], 'input_unit_id' => $baseUnitId,
                'unit_price_input' => (float) $line['unit_cost_base'],
                'allow_zero_price' => true, // a zero-cost opening was already flagged WARNING at staging and reviewed before commit — not a fake default
                'transaction_type' => 'OPENING',
                'transaction_date' => $opening['cutoff_date'] . ' 00:00:00',
                'reference_no' => "OPENING-{$openingId}",
                'expiry_date' => $line['expiry_date'],
                'created_by' => $committedBy,
                'anomaly_approved_by' => $committedBy, // an opening baseline is not a purchase price; anomaly check does not apply
            ]);

            $pdo->prepare('UPDATE stock_opening_lines SET created_batch_id = :batch_id WHERE id = :id')
                ->execute(['batch_id' => $result['batch_id'], 'id' => $line['id']]);
            $created++;
        }

        $pdo->prepare('UPDATE stock_openings SET status = \'COMMITTED\', committed_by = :by, committed_at = :now WHERE id = :id')
            ->execute(['by' => $committedBy, 'now' => date('Y-m-d H:i:s'), 'id' => $openingId]);

        AuditService::log($pdo, $committedBy, 'system', 'OPENING_IMPORT', 'stock_openings', $openingId, null, ['lines_created' => $created], null);

        return ['imported' => $created];
    }

    private static function baseUnitId(PDO $pdo, int $itemId): int
    {
        $stmt = $pdo->prepare('SELECT base_unit_id FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        return (int) $stmt->fetchColumn();
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
