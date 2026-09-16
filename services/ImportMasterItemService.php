<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Phase E: Master Barang importer. Implements the staging flow from
 * Section 16 of the brief — nothing lands in `items` until an explicit
 * commit, and commit is refused outright while any row is ERROR.
 *
 * Expected CSV header (see templates/master_items.csv):
 * sku,barcode,name,category,brand,base_unit,purchase_unit,purchase_conversion,middle_unit,middle_conversion,minimum_stock,status
 */
final class ImportMasterItemService
{
    public static function stage(PDO $pdo, string $csvPath, string $fileName, int $uploadedBy): int
    {
        $rows = self::readCsv($csvPath);

        $batchStmt = $pdo->prepare(
            'INSERT INTO import_batches (import_type, file_name, status, total_rows, uploaded_by, uploaded_at)
             VALUES (\'MASTER_ITEM\', :file_name, \'STAGED\', :total, :uploaded_by, :uploaded_at)'
        );
        $batchStmt->execute([
            'file_name' => $fileName, 'total' => count($rows),
            'uploaded_by' => $uploadedBy, 'uploaded_at' => date('Y-m-d H:i:s'),
        ]);
        $importBatchId = (int) $pdo->lastInsertId();

        $seenSku = [];
        $counts = ['VALID' => 0, 'WARNING' => 0, 'ERROR' => 0];
        $rowStmt = $pdo->prepare(
            'INSERT INTO import_rows (import_batch_id, row_no, raw_data, row_status, messages)
             VALUES (:batch_id, :row_no, :raw, :status, :messages)'
        );

        foreach ($rows as $i => $row) {
            [$status, $messages] = self::validateRow($pdo, $row, $seenSku);
            $counts[$status]++;
            if ($status !== 'ERROR' && $row['sku'] !== '') {
                $seenSku[$row['sku']] = true;
            }
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
    private static function validateRow(PDO $pdo, array $row, array $seenSku): array
    {
        $messages = [];
        $sku = trim((string) ($row['sku'] ?? ''));

        if ($sku === '') {
            return ['ERROR', ['sku is required']];
        }
        if (isset($seenSku[$sku])) {
            return ['ERROR', ["duplicate SKU within this import file: {$sku}"]];
        }
        $exists = $pdo->prepare('SELECT COUNT(*) FROM items WHERE sku = :sku');
        $exists->execute(['sku' => $sku]);
        if ((int) $exists->fetchColumn() > 0) {
            return ['ERROR', ["SKU already exists in master: {$sku}"]];
        }
        if (trim((string) ($row['name'] ?? '')) === '') {
            return ['ERROR', ['name is required']];
        }
        if (trim((string) ($row['base_unit'] ?? '')) === '') {
            return ['ERROR', ['base_unit is required']];
        }

        $purchaseConv = $row['purchase_conversion'] ?? '';
        if ($purchaseConv !== '' && !((float) $purchaseConv > 0)) {
            return ['ERROR', ['purchase_conversion must be > 0 when purchase_unit is set']];
        }
        $middleConv = $row['middle_conversion'] ?? '';
        if ($middleConv !== '' && !((float) $middleConv > 0)) {
            return ['ERROR', ['middle_conversion must be > 0 when middle_unit is set']];
        }

        $status = strtoupper(trim((string) ($row['status'] ?? 'ACTIVE')));
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['ERROR', ["status must be ACTIVE or INACTIVE, got: {$status}"]];
        }

        if (($row['minimum_stock'] ?? '') !== '' && (float) $row['minimum_stock'] < 0) {
            return ['ERROR', ['minimum_stock cannot be negative']];
        }

        if (trim((string) ($row['barcode'] ?? '')) === '') {
            $messages[] = 'no barcode supplied';
            return ['WARNING', $messages];
        }

        return ['VALID', $messages];
    }

    /** Refuses to commit while any row is ERROR (Section 16: "Jika ada ERROR: IMPORT DITOLAK"). */
    public static function commit(PDO $pdo, int $importBatchId, int $committedBy): array
    {
        $batch = $pdo->prepare('SELECT * FROM import_batches WHERE id = :id');
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
            $itemId = self::createItem($pdo, $data, $committedBy);
            $pdo->prepare('UPDATE import_rows SET created_entity_id = :entity_id WHERE id = :id')
                ->execute(['entity_id' => $itemId, 'id' => $importRow['id']]);
            $created++;
        }

        $pdo->prepare('UPDATE import_batches SET status = \'COMMITTED\', committed_by = :by, committed_at = :at WHERE id = :id')
            ->execute(['by' => $committedBy, 'at' => date('Y-m-d H:i:s'), 'id' => $importBatchId]);

        return ['imported' => $created, 'skipped' => 0];
    }

    private static function createItem(PDO $pdo, array $row, int $createdBy): int
    {
        $baseUnitId = self::resolveUnitId($pdo, $row['base_unit']);

        $stmt = $pdo->prepare(
            'INSERT INTO items (sku, barcode, name, category, brand, base_unit_id, minimum_stock, status, created_at, updated_at)
             VALUES (:sku, :barcode, :name, :category, :brand, :base_unit_id, :min_stock, :status, :now, :now)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'sku' => $row['sku'], 'barcode' => $row['barcode'] ?: null, 'name' => $row['name'],
            'category' => $row['category'] ?: null, 'brand' => $row['brand'] ?: null,
            'base_unit_id' => $baseUnitId, 'min_stock' => (float) ($row['minimum_stock'] ?: 0),
            'status' => strtoupper($row['status'] ?: 'ACTIVE'), 'now' => $now,
        ]);
        $itemId = (int) $pdo->lastInsertId();

        UnitConversionService::openNewVersion($pdo, $itemId, $baseUnitId, 1.0, $now, $createdBy, 'identity (base unit)');

        if (!empty($row['purchase_unit']) && !empty($row['purchase_conversion'])) {
            $purchaseUnitId = self::resolveUnitId($pdo, $row['purchase_unit']);
            UnitConversionService::openNewVersion(
                $pdo, $itemId, $purchaseUnitId, (float) $row['purchase_conversion'], $now, $createdBy,
                'initial import', true
            );
        }
        if (!empty($row['middle_unit']) && !empty($row['middle_conversion'])) {
            $middleUnitId = self::resolveUnitId($pdo, $row['middle_unit']);
            UnitConversionService::openNewVersion(
                $pdo, $itemId, $middleUnitId, (float) $row['middle_conversion'], $now, $createdBy, 'initial import'
            );
        }

        return $itemId;
    }

    private static function resolveUnitId(PDO $pdo, string $code): int
    {
        $code = strtoupper(trim($code));
        $stmt = $pdo->prepare('SELECT id FROM units WHERE code = :code');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $pdo->prepare('INSERT INTO units (code, name) VALUES (:code, :code)')->execute(['code' => $code]);
        return (int) $pdo->lastInsertId();
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
