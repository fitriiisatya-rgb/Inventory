<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE E2: Supplier / Division / Warehouse all share the same three-column
 * template (code, name, status) and the same staging pattern as
 * ImportMasterItemService — one class covers all three rather than
 * duplicating near-identical staging code per entity.
 */
final class ImportSimpleMasterService
{
    private const TABLES = [
        'SUPPLIER' => 'suppliers',
        'DIVISION' => 'divisions',
        'WAREHOUSE' => 'warehouses',
    ];

    // CSV header column names differ per template (Section 13 of the brief
    // spells out "Supplier Code"/"Division Code"/"Warehouse Code" etc.
    // rather than one generic "code"), so map them here instead of forcing
    // the templates to a generic shape.
    private const COLUMNS = [
        'SUPPLIER' => ['code' => 'supplier_code', 'name' => 'supplier_name'],
        'DIVISION' => ['code' => 'division_code', 'name' => 'division_name'],
        'WAREHOUSE' => ['code' => 'warehouse_code', 'name' => 'warehouse_name'],
    ];

    public static function stage(PDO $pdo, string $importType, string $csvPath, string $fileName, int $uploadedBy): int
    {
        self::assertKnownType($importType);
        $table = self::TABLES[$importType];
        $rows = self::readCsv($csvPath);

        $batchStmt = $pdo->prepare(
            'INSERT INTO import_batches (import_type, file_name, status, total_rows, uploaded_by, uploaded_at)
             VALUES (:type, :file_name, \'STAGED\', :total, :uploaded_by, :uploaded_at)'
        );
        $batchStmt->execute([
            'type' => $importType, 'file_name' => $fileName, 'total' => count($rows),
            'uploaded_by' => $uploadedBy, 'uploaded_at' => date('Y-m-d H:i:s'),
        ]);
        $importBatchId = (int) $pdo->lastInsertId();

        $seenCode = [];
        $counts = ['VALID' => 0, 'WARNING' => 0, 'ERROR' => 0];
        $rowStmt = $pdo->prepare(
            'INSERT INTO import_rows (import_batch_id, row_no, raw_data, row_status, messages)
             VALUES (:batch_id, :row_no, :raw, :status, :messages)'
        );

        $columns = self::COLUMNS[$importType];
        foreach ($rows as $i => $row) {
            [$status, $messages] = self::validateRow($pdo, $table, $columns, $row, $seenCode);
            $counts[$status]++;
            $code = trim((string) ($row[$columns['code']] ?? ''));
            if ($status !== 'ERROR' && $code !== '') {
                $seenCode[$code] = true;
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
    private static function validateRow(PDO $pdo, string $table, array $columns, array $row, array $seenCode): array
    {
        $code = trim((string) ($row[$columns['code']] ?? ''));
        $name = trim((string) ($row[$columns['name']] ?? ''));

        if ($code === '') {
            return ['ERROR', ['code is required']];
        }
        if (isset($seenCode[$code])) {
            return ['ERROR', ["duplicate code within this import file: {$code}"]];
        }
        $exists = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE code = :code");
        $exists->execute(['code' => $code]);
        if ((int) $exists->fetchColumn() > 0) {
            return ['ERROR', ["code already exists in master: {$code}"]];
        }
        if ($name === '') {
            return ['ERROR', ['name is required']];
        }
        $status = strtoupper(trim((string) ($row['status'] ?? 'ACTIVE')));
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['ERROR', ["status must be ACTIVE or INACTIVE, got: {$status}"]];
        }

        return ['VALID', []];
    }

    public static function commit(PDO $pdo, string $importType, int $importBatchId, int $committedBy): array
    {
        self::assertKnownType($importType);
        $table = self::TABLES[$importType];

        $batch = $pdo->prepare('SELECT * FROM import_batches WHERE id = :id AND import_type = :type');
        $batch->execute(['id' => $importBatchId, 'type' => $importType]);
        $batch = $batch->fetch();
        if (!$batch) {
            throw new NotFoundException('import batch not found for this type');
        }
        if ((int) $batch['error_rows'] > 0) {
            throw new ImportValidationException(['import rejected: batch has ' . $batch['error_rows'] . ' ERROR row(s)']);
        }

        $rowsStmt = $pdo->prepare(
            "SELECT * FROM import_rows WHERE import_batch_id = :id AND row_status = 'VALID' ORDER BY row_no"
        );
        $rowsStmt->execute(['id' => $importBatchId]);
        $rows = $rowsStmt->fetchAll();

        $columns = self::COLUMNS[$importType];
        $insert = $pdo->prepare("INSERT INTO {$table} (code, name, is_active) VALUES (:code, :name, :active)");
        $created = 0;
        foreach ($rows as $importRow) {
            $data = json_decode($importRow['raw_data'], true);
            $insert->execute([
                'code' => trim($data[$columns['code']]), 'name' => trim($data[$columns['name']]),
                'active' => strtoupper(trim($data['status'] ?? 'ACTIVE')) === 'ACTIVE' ? 1 : 0,
            ]);
            $entityId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE import_rows SET created_entity_id = :entity_id WHERE id = :id')
                ->execute(['entity_id' => $entityId, 'id' => $importRow['id']]);
            $created++;
        }

        $pdo->prepare('UPDATE import_batches SET status = \'COMMITTED\', committed_by = :by, committed_at = :at WHERE id = :id')
            ->execute(['by' => $committedBy, 'at' => date('Y-m-d H:i:s'), 'id' => $importBatchId]);

        return ['imported' => $created, 'skipped' => 0];
    }

    private static function assertKnownType(string $importType): void
    {
        if (!isset(self::TABLES[$importType])) {
            throw new ValidationException(["unknown import type: {$importType}"]);
        }
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
