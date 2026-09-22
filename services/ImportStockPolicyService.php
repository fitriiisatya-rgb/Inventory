<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.9 — bulk "Minimum Stock per Gudang" import. Reuses the existing
 * generic import_batches/import_rows staging tables (import_type
 * MINIMUM_STOCK) and posts every row through the real, unmodified
 * StockPolicyService::upsert() — never a second policy-write path, never a
 * new item_warehouse_stock_policy-equivalent table.
 *
 * Expected CSV/XLSX header: sku, warehouse_code, minimum_stock,
 * buffer_stock (buffer_stock is optional — StockPolicyService already
 * treats it as "not configured" when omitted, exactly like the manual
 * edit UI leaving it blank).
 *
 * Karang Tengah / any PENDING_CUTOVER warehouse safety: warehouse_code is
 * resolved with is_active = 1 at BOTH staging and commit time (same
 * pattern as ImportLiveTransactionService and StockPolicyService::upsert()
 * itself, which independently re-checks is_active as its own
 * defense-in-depth) — a row targeting an inactive warehouse is a hard
 * ERROR, never silently skipped or silently activated.
 *
 * Atomicity: the caller wraps commit() in Database::transaction(), same
 * as every other importer — any row failure rolls back every upsert
 * already performed in that same commit() call.
 */
final class ImportStockPolicyService
{
    public static function stage(PDO $pdo, string $filePath, string $fileName, int $uploadedBy): int
    {
        $rows = str_ends_with(strtolower($fileName), '.xlsx')
            ? XlsxReaderService::read($filePath)
            : self::readCsv($filePath);
        if (empty($rows)) {
            throw new ValidationException(['file has no data rows']);
        }

        $batchStmt = $pdo->prepare(
            "INSERT INTO import_batches (import_type, file_name, status, total_rows, uploaded_by, uploaded_at)
             VALUES ('MINIMUM_STOCK', :file_name, 'STAGED', :total, :uploaded_by, :uploaded_at)"
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
        $sku = trim((string) ($row['sku'] ?? ''));
        $item = $pdo->prepare('SELECT id FROM items WHERE sku = :sku');
        $item->execute(['sku' => $sku]);
        if ($item->fetchColumn() === false) {
            return ['ERROR', ["unknown SKU: {$sku}"]];
        }

        $whCode = trim((string) ($row['warehouse_code'] ?? ''));
        $wh = $pdo->prepare('SELECT id FROM warehouses WHERE code = :code AND is_active = 1');
        $wh->execute(['code' => $whCode]);
        if ($wh->fetchColumn() === false) {
            return ['ERROR', ["unknown or inactive warehouse_code: {$whCode}"]];
        }

        $minimum = $row['minimum_stock'] ?? '';
        if ($minimum === '' || !is_numeric($minimum) || (float) $minimum < 0) {
            return ['ERROR', ['minimum_stock must be a number >= 0']];
        }

        $buffer = $row['buffer_stock'] ?? '';
        if ($buffer !== '' && (!is_numeric($buffer) || (float) $buffer < 0)) {
            return ['ERROR', ['buffer_stock, if given, must be a number >= 0']];
        }

        return ['VALID', []];
    }

    public static function commit(PDO $pdo, int $importBatchId, int $committedBy): array
    {
        $batch = $pdo->prepare("SELECT * FROM import_batches WHERE id = :id AND import_type = 'MINIMUM_STOCK'");
        $batch->execute(['id' => $importBatchId]);
        $batch = $batch->fetch();
        if (!$batch) {
            throw new NotFoundException('import batch not found');
        }
        if ((int) $batch['error_rows'] > 0) {
            throw new ImportValidationException(['import rejected: batch has ' . $batch['error_rows'] . ' ERROR row(s)']);
        }

        $committerStmt = $pdo->prepare('SELECT username FROM users WHERE id = :id');
        $committerStmt->execute(['id' => $committedBy]);
        $committerUsername = (string) ($committerStmt->fetchColumn() ?: 'system');

        $rowsStmt = $pdo->prepare(
            "SELECT * FROM import_rows WHERE import_batch_id = :id AND row_status IN ('VALID','WARNING') ORDER BY row_no"
        );
        $rowsStmt->execute(['id' => $importBatchId]);
        $rows = $rowsStmt->fetchAll();

        $created = 0;
        foreach ($rows as $importRow) {
            $data = json_decode($importRow['raw_data'], true);

            $itemStmt = $pdo->prepare('SELECT id FROM items WHERE sku = :sku');
            $itemStmt->execute(['sku' => trim((string) $data['sku'])]);
            $itemId = (int) $itemStmt->fetchColumn();

            $whStmt = $pdo->prepare('SELECT id FROM warehouses WHERE code = :code AND is_active = 1');
            $whStmt->execute(['code' => trim((string) $data['warehouse_code'])]);
            $warehouseId = $whStmt->fetchColumn();
            if ($warehouseId === false) {
                throw new ValidationException(["row {$importRow['row_no']}: warehouse_code " . $data['warehouse_code'] . ' is unknown or no longer active']);
            }

            $result = StockPolicyService::upsert($pdo, [
                'item_id' => $itemId, 'warehouse_id' => (int) $warehouseId,
                'minimum_stock' => (float) $data['minimum_stock'],
                'buffer_stock' => isset($data['buffer_stock']) && $data['buffer_stock'] !== '' ? (float) $data['buffer_stock'] : null,
                'updated_by' => $committedBy, 'username' => $committerUsername,
                'notes' => 'bulk import batch #' . $importBatchId,
            ]);
            $pdo->prepare('UPDATE import_rows SET created_entity_id = :id2 WHERE id = :id')
                ->execute(['id2' => $result['policy_id'], 'id' => $importRow['id']]);
            $created++;
        }

        $pdo->prepare('UPDATE import_batches SET status = \'COMMITTED\', committed_by = :by, committed_at = :now WHERE id = :id')
            ->execute(['by' => $committedBy, 'now' => date('Y-m-d H:i:s'), 'id' => $importBatchId]);

        AuditService::log($pdo, $committedBy, $committerUsername, 'MINIMUM_STOCK_IMPORT', 'import_batches', $importBatchId, null, ['rows_created' => $created], null);

        return ['imported' => $created];
    }

    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new ValidationException(["cannot open file: {$path}"]);
        }
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];
        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (count($line) === 1 && $line[0] === null) {
                continue;
            }
            $rows[] = array_combine($header, array_pad($line, count($header), ''));
        }
        fclose($handle);
        return $rows;
    }
}
