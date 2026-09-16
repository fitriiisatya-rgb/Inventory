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
        $seenBarcode = [];
        $counts = ['VALID' => 0, 'WARNING' => 0, 'ERROR' => 0];
        $rowStmt = $pdo->prepare(
            'INSERT INTO import_rows (import_batch_id, row_no, raw_data, row_status, messages)
             VALUES (:batch_id, :row_no, :raw, :status, :messages)'
        );

        foreach ($rows as $i => $row) {
            [$status, $messages] = self::validateRow($pdo, $row, $seenSku, $seenBarcode);
            $counts[$status]++;
            if ($status !== 'ERROR' && $row['sku'] !== '') {
                $seenSku[$row['sku']] = true;
            }
            if ($status !== 'ERROR' && !empty($row['barcode'])) {
                $seenBarcode[trim((string) $row['barcode'])] = true;
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

    // PHASE G2.2: thresholds for WARNING-level anomaly flags. Deliberately
    // generous — these exist to prompt a human review, never to block a
    // commit or silently reject/rewrite a row (see G11: no silent fix).
    private const MINIMUM_STOCK_WARNING_THRESHOLD = 1_000_000;
    private const CONVERSION_LOW_WARNING_THRESHOLD = 0.001;
    private const CONVERSION_HIGH_WARNING_THRESHOLD = 1_000_000;
    private const NAME_SIMILARITY_MAX_DISTANCE = 2;

    /** @return array{0:string,1:string[]} */
    private static function validateRow(PDO $pdo, array $row, array $seenSku, array $seenBarcode = []): array
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

        $rawBaseUnit = trim((string) ($row['base_unit'] ?? ''));
        if ($rawBaseUnit === '') {
            return ['ERROR', ['base_unit is required']];
        }
        if (UnitNormalizationService::normalize($pdo, $rawBaseUnit) === null) {
            return ['ERROR', ["base_unit is not a recognized unit: {$rawBaseUnit}"]];
        }

        $rawPurchaseUnit = trim((string) ($row['purchase_unit'] ?? ''));
        $purchaseConv = $row['purchase_conversion'] ?? '';
        if ($rawPurchaseUnit !== '' && UnitNormalizationService::normalize($pdo, $rawPurchaseUnit) === null) {
            return ['ERROR', ["purchase_unit is not a recognized unit: {$rawPurchaseUnit}"]];
        }
        if ($purchaseConv !== '' && !((float) $purchaseConv > 0)) {
            return ['ERROR', ['purchase_conversion must be > 0 when purchase_unit is set']];
        }

        $rawMiddleUnit = trim((string) ($row['middle_unit'] ?? ''));
        $middleConv = $row['middle_conversion'] ?? '';
        if ($rawMiddleUnit !== '' && UnitNormalizationService::normalize($pdo, $rawMiddleUnit) === null) {
            return ['ERROR', ["middle_unit is not a recognized unit: {$rawMiddleUnit}"]];
        }
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

        // ---- from here on: WARNING only, never ERROR — flag for human review ----
        $barcode = trim((string) ($row['barcode'] ?? ''));
        if ($barcode === '') {
            $messages[] = 'no barcode supplied';
        } else {
            if (isset($seenBarcode[$barcode])) {
                $messages[] = "barcode duplicates another row in this same file: {$barcode}";
            } else {
                $dupBarcode = $pdo->prepare('SELECT sku FROM items WHERE barcode = :barcode LIMIT 1');
                $dupBarcode->execute(['barcode' => $barcode]);
                $existingSku = $dupBarcode->fetchColumn();
                if ($existingSku !== false) {
                    $messages[] = "barcode already used by existing item {$existingSku}";
                }
            }
        }

        if (($row['minimum_stock'] ?? '') !== '' && (float) $row['minimum_stock'] > self::MINIMUM_STOCK_WARNING_THRESHOLD) {
            $messages[] = 'minimum_stock is unusually large — please verify (' . $row['minimum_stock'] . ')';
        }

        foreach (['purchase_conversion' => $purchaseConv, 'middle_conversion' => $middleConv] as $field => $value) {
            if ($value === '') {
                continue;
            }
            $f = (float) $value;
            if ($f < self::CONVERSION_LOW_WARNING_THRESHOLD || $f > self::CONVERSION_HIGH_WARNING_THRESHOLD) {
                $messages[] = "{$field} looks unusual, please verify ({$value})";
            }
        }

        if (!empty($row['default_supplier_code'])) {
            $supplierCode = trim((string) $row['default_supplier_code']);
            $supplierExists = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE code = :code');
            $supplierExists->execute(['code' => $supplierCode]);
            if ((int) $supplierExists->fetchColumn() === 0) {
                $messages[] = "default_supplier_code not found in supplier master, will be left blank: {$supplierCode}";
            }
        }

        $similar = self::findSimilarName($pdo, trim((string) $row['name']), $sku);
        if ($similar !== null) {
            $messages[] = "name is very similar to existing item {$similar['sku']} ({$similar['name']}) — possible duplicate, please verify";
        }

        return [empty($messages) ? 'VALID' : 'WARNING', $messages];
    }

    /** @return array{sku:string,name:string}|null the closest existing item name within NAME_SIMILARITY_MAX_DISTANCE, or null */
    private static function findSimilarName(PDO $pdo, string $name, string $excludeSku): ?array
    {
        if (mb_strlen($name) < 5) {
            return null; // too short for edit-distance similarity to mean anything
        }
        $candidates = $pdo->prepare('SELECT sku, name FROM items WHERE sku <> :sku');
        $candidates->execute(['sku' => $excludeSku]);
        foreach ($candidates->fetchAll() as $candidate) {
            if (levenshtein(mb_strtolower($name), mb_strtolower($candidate['name'])) <= self::NAME_SIMILARITY_MAX_DISTANCE) {
                return $candidate;
            }
        }
        return null;
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

        AuditService::log($pdo, $committedBy, 'system', 'MASTER_ITEM_IMPORT', 'import_batches', $importBatchId, null, ['items_created' => $created], null);

        return ['imported' => $created, 'skipped' => 0];
    }

    private static function createItem(PDO $pdo, array $row, int $createdBy): int
    {
        // Re-validated here (not just trusted from staging) — normalize() is
        // deterministic and cheap, and this guarantees commit() can never
        // silently fall back to auto-creating an unrecognized unit even if
        // the units table changed between stage() and commit().
        $baseUnitId = UnitNormalizationService::resolveUnitId($pdo, $row['base_unit']);
        if ($baseUnitId === null) {
            throw new ImportValidationException(["base_unit is not a recognized unit: {$row['base_unit']}"]);
        }

        $defaultSupplierId = null;
        if (!empty($row['default_supplier_code'])) {
            $supplierStmt = $pdo->prepare('SELECT id FROM suppliers WHERE code = :code');
            $supplierStmt->execute(['code' => trim((string) $row['default_supplier_code'])]);
            $found = $supplierStmt->fetchColumn();
            $defaultSupplierId = $found !== false ? (int) $found : null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO items (sku, barcode, name, category, brand, base_unit_id, minimum_stock, default_supplier_id, notes, status, created_at, updated_at)
             VALUES (:sku, :barcode, :name, :category, :brand, :base_unit_id, :min_stock, :default_supplier_id, :notes, :status, :now, :now2)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'sku' => $row['sku'], 'barcode' => $row['barcode'] ?: null, 'name' => $row['name'],
            'category' => $row['category'] ?: null, 'brand' => $row['brand'] ?: null,
            'base_unit_id' => $baseUnitId, 'min_stock' => (float) ($row['minimum_stock'] ?: 0),
            'default_supplier_id' => $defaultSupplierId, 'notes' => $row['notes'] ?? null,
            'status' => strtoupper($row['status'] ?: 'ACTIVE'), 'now' => $now, 'now2' => $now,
        ]);
        $itemId = (int) $pdo->lastInsertId();

        UnitConversionService::openNewVersion($pdo, $itemId, $baseUnitId, 1.0, $now, $createdBy, 'identity (base unit)');

        if (!empty($row['purchase_unit']) && !empty($row['purchase_conversion'])) {
            $purchaseUnitId = UnitNormalizationService::resolveUnitId($pdo, $row['purchase_unit']);
            if ($purchaseUnitId === null) {
                throw new ImportValidationException(["purchase_unit is not a recognized unit: {$row['purchase_unit']}"]);
            }
            UnitConversionService::openNewVersion(
                $pdo, $itemId, $purchaseUnitId, (float) $row['purchase_conversion'], $now, $createdBy,
                'initial import', true
            );
        }
        if (!empty($row['middle_unit']) && !empty($row['middle_conversion'])) {
            $middleUnitId = UnitNormalizationService::resolveUnitId($pdo, $row['middle_unit']);
            if ($middleUnitId === null) {
                throw new ImportValidationException(["middle_unit is not a recognized unit: {$row['middle_unit']}"]);
            }
            UnitConversionService::openNewVersion(
                $pdo, $itemId, $middleUnitId, (float) $row['middle_conversion'], $now, $createdBy, 'initial import'
            );
        }

        return $itemId;
    }

    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new ValidationException(["cannot open file: {$path}"]);
        }
        $header = fgetcsv($handle, null, ",", "\"", "");
        $rows = [];
        while (($line = fgetcsv($handle, null, ",", "\"", "")) !== false) {
            if (count($line) === 1 && $line[0] === null) {
                continue;
            }
            $rows[] = array_combine($header, array_pad($line, count($header), ''));
        }
        fclose($handle);
        return $rows;
    }
}
