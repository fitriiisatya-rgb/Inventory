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
 * PHASE G-DATA 2: also accepts final_opening_stock_template.xlsx directly
 * (migration/templates/final_opening_stock_template.xlsx) — sniffed by
 * file extension — in addition to the original CSV format, and always
 * posts in the item's Global Base Unit (Section 1's LOW-confidence
 * policy: a purchase-unit conversion is never required to post an
 * opening line). Row-level validation now lives in
 * OpeningValidationService so it can be unit-tested independently.
 *
 * Expected CSV/XLSX columns (template header names; the original CSV's
 * lowercase snake_case names remain accepted as aliases — see
 * OpeningValidationService and normalizeRow() below):
 *   Cutoff Date, Warehouse Code, SKU, Item Name, Global Base Unit,
 *   Opening Qty Base, Unit Cost Base, Opening Value (ignored — server
 *   recalculates), Expiry Date, Batch Reference, Source,
 *   Verification Status, Approved By, Notes
 */
final class ImportOpeningStockService
{
    // PHASE G15: control-total mismatch tolerance — anything beyond plain
    // decimal rounding is treated as a real discrepancy, never silently
    // accepted.
    private const CONTROL_TOTAL_TOLERANCE = 1.0;

    public static function stage(PDO $pdo, string $filePath, string $fileName, int $createdBy): int
    {
        $rows = str_ends_with(strtolower($fileName), '.xlsx')
            ? self::readXlsx($filePath)
            : self::readCsv($filePath);
        if (empty($rows)) {
            throw new ValidationException(['file has no data rows']);
        }

        $cutoffError = OpeningValidationService::checkCutoffConsistency($rows);
        if ($cutoffError !== null) {
            throw new ValidationException([$cutoffError]);
        }

        $cutoffDate = trim((string) self::col($rows[0], 'cutoff_date', 'Cutoff Date'));
        if ($cutoffDate === '') {
            throw new ValidationException(['Cutoff Date is required']);
        }

        $headerStmt = $pdo->prepare(
            'INSERT INTO stock_openings (cutoff_date, description, status, created_by, created_at)
             VALUES (:cutoff, :description, \'DRAFT\', :created_by, :now)'
        );
        $headerStmt->execute(['cutoff' => $cutoffDate, 'description' => $fileName, 'created_by' => $createdBy, 'now' => date('Y-m-d H:i:s')]);
        $openingId = (int) $pdo->lastInsertId();

        $lineStmt = $pdo->prepare(
            'INSERT INTO stock_opening_lines
                (stock_opening_id, item_id, warehouse_id, qty_base, unit_cost_base, expiry_date, batch_reference,
                 item_name_reference, global_base_unit_reference, source, verification_status, approved_by_name, notes,
                 row_status, row_messages)
             VALUES (:opening_id, :item_id, :warehouse_id, :qty, :cost, :expiry, :batch_ref,
                     :item_name_ref, :base_unit_ref, :source, :verification_status, :approved_by_name, :notes,
                     :status, :messages)'
        );

        $seen = [];
        foreach ($rows as $row) {
            $row = self::normalizeRow($row);
            $result = OpeningValidationService::validateRow($pdo, $row);
            $status = $result['status'];
            $messages = $result['messages'];

            // Friendly duplicate pre-check (the DB unique key on
            // stock_opening_id+item_id+warehouse_id+batch_reference is the
            // real backstop, but a clear message here beats a raw
            // constraint-violation error surfacing to the user).
            $dupKey = ($result['item_id'] ?? 'null') . '|' . ($result['warehouse_id'] ?? 'null') . '|' . trim((string) ($row['batch_reference'] ?? ''));
            if ($status !== 'ERROR' && isset($seen[$dupKey])) {
                $status = 'ERROR';
                $messages[] = 'duplicate warehouse + SKU + batch_reference within this same upload';
            }
            $seen[$dupKey] = true;

            $lineStmt->execute([
                'opening_id' => $openingId, 'item_id' => $result['item_id'], 'warehouse_id' => $result['warehouse_id'],
                'qty' => is_numeric($row['opening_qty_base'] ?? null) ? (float) $row['opening_qty_base'] : 0,
                'cost' => is_numeric($row['unit_cost_base'] ?? null) ? (float) $row['unit_cost_base'] : 0,
                'expiry' => $row['expiry_date'] !== '' ? $row['expiry_date'] : null,
                'batch_ref' => $row['batch_reference'] !== '' ? $row['batch_reference'] : null,
                'item_name_ref' => $row['item_name'] !== '' ? $row['item_name'] : null,
                'base_unit_ref' => $row['global_base_unit'] !== '' ? strtoupper($row['global_base_unit']) : null,
                'source' => $row['source'] !== '' ? $row['source'] : null,
                'verification_status' => $row['verification_status'] !== '' ? $row['verification_status'] : null,
                'approved_by_name' => $row['approved_by'] !== '' ? $row['approved_by'] : null,
                'notes' => $row['notes'] !== '' ? $row['notes'] : null,
                'status' => $status, 'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE),
            ]);
        }

        self::recordControlTotal($pdo, $openingId);

        return $openingId;
    }

    /**
     * Accepts either the new template's Capitalized-With-Spaces headers or
     * the original lowercase snake_case CSV headers — never both mixed
     * silently wrong; each column simply falls back through its aliases.
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'cutoff_date' => trim((string) self::col($row, 'cutoff_date', 'Cutoff Date')),
            'warehouse_code' => trim((string) self::col($row, 'warehouse_code', 'Warehouse Code')),
            'sku' => trim((string) self::col($row, 'sku', 'SKU')),
            'item_name' => trim((string) self::col($row, 'item_name', 'Item Name', 'Item Name (reference)')),
            'global_base_unit' => trim((string) self::col($row, 'global_base_unit', 'Global Base Unit')),
            'opening_qty_base' => self::col($row, 'opening_qty_base', 'Opening Qty Base', 'quantity_base'),
            'unit_cost_base' => self::col($row, 'unit_cost_base', 'Unit Cost Base'),
            'expiry_date' => trim((string) self::col($row, 'expiry_date', 'Expiry Date', 'expired_date')),
            'batch_reference' => trim((string) self::col($row, 'batch_reference', 'Batch Reference')),
            'source' => trim((string) self::col($row, 'source', 'Source')),
            'verification_status' => trim((string) self::col($row, 'verification_status', 'Verification Status')),
            'approved_by' => trim((string) self::col($row, 'approved_by', 'Approved By')),
            'notes' => trim((string) self::col($row, 'notes', 'Notes')),
        ];
    }

    private static function col(array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                return (string) $row[$key];
            }
        }
        return '';
    }

    /**
     * PHASE G15: snapshots the expected total value (overall + per
     * warehouse) from the rows AS STAGED — computed once, before commit
     * ever runs, so commit() has an independent figure to check itself
     * against rather than trusting its own arithmetic.
     */
    private static function recordControlTotal(PDO $pdo, int $openingId): void
    {
        $overall = $pdo->prepare(
            "SELECT COALESCE(SUM(qty_base * unit_cost_base), 0) FROM stock_opening_lines
             WHERE stock_opening_id = :id AND row_status IN ('VALID','WARNING')"
        );
        $overall->execute(['id' => $openingId]);
        $total = round((float) $overall->fetchColumn(), 4);

        $perWarehouse = $pdo->prepare(
            "SELECT w.code, COALESCE(SUM(sol.qty_base * sol.unit_cost_base), 0) AS value
             FROM stock_opening_lines sol
             JOIN warehouses w ON w.id = sol.warehouse_id
             WHERE sol.stock_opening_id = :id AND sol.row_status IN ('VALID','WARNING')
             GROUP BY w.code"
        );
        $perWarehouse->execute(['id' => $openingId]);
        $byWarehouse = [];
        foreach ($perWarehouse->fetchAll() as $row) {
            $byWarehouse[$row['code']] = round((float) $row['value'], 4);
        }

        $pdo->prepare('UPDATE stock_openings SET control_total_value = :total, control_total_by_warehouse = :by_wh WHERE id = :id')
            ->execute(['total' => $total, 'by_wh' => json_encode($byWarehouse, JSON_UNESCAPED_UNICODE), 'id' => $openingId]);
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

        // PHASE G15: verify what actually landed matches the control total
        // computed at staging time, BEFORE flipping status to COMMITTED.
        // A mismatch throws here — the caller always wraps commit() in
        // Database::transaction(), so this exception rolls back every batch
        // just created in this loop rather than leaving a partial import.
        self::assertControlTotalMatches($pdo, $openingId, (float) $opening['control_total_value']);

        $pdo->prepare('UPDATE stock_openings SET status = \'COMMITTED\', committed_by = :by, committed_at = :now WHERE id = :id')
            ->execute(['by' => $committedBy, 'now' => date('Y-m-d H:i:s'), 'id' => $openingId]);

        AuditService::log($pdo, $committedBy, 'system', 'OPENING_IMPORT', 'stock_openings', $openingId, null, ['lines_created' => $created, 'control_total_value' => $opening['control_total_value']], null);

        return ['imported' => $created, 'control_total_value' => (float) $opening['control_total_value']];
    }

    private static function assertControlTotalMatches(PDO $pdo, int $openingId, float $expectedTotal): void
    {
        $actual = $pdo->prepare(
            'SELECT COALESCE(SUM(b.qty_base * b.unit_cost_base), 0)
             FROM stock_opening_lines sol
             JOIN inventory_batches b ON b.id = sol.created_batch_id
             WHERE sol.stock_opening_id = :id AND sol.created_batch_id IS NOT NULL'
        );
        $actual->execute(['id' => $openingId]);
        $actualTotal = round((float) $actual->fetchColumn(), 4);

        if (abs($expectedTotal - $actualTotal) > self::CONTROL_TOTAL_TOLERANCE) {
            throw new ValidationException([
                "opening control total mismatch: staged total was Rp" . number_format($expectedTotal, 2) .
                " but committed batches total Rp" . number_format($actualTotal, 2) .
                " — import rolled back, nothing was committed",
            ]);
        }
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

    /**
     * Dependency-free .xlsx reader (an xlsx is just a zip of XML parts) —
     * reads the first sheet's shared strings + cell grid. No Composer
     * package is used anywhere in this codebase (Section 2 constraint),
     * so this stays intentionally minimal: it reads text/number cell
     * values only, exactly what the opening template needs.
     */
    private static function readXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ValidationException(["cannot open xlsx file: {$path}"]);
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sst = simplexml_load_string($sharedXml);
            foreach ($sst->si as $si) {
                $sharedStrings[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', (array) ($si->r ?? [])));
            }
        }

        // The data sheet is NOT reliably "sheet1.xml" — an INSTRUCTIONS sheet
        // (as in final_opening_stock_template.xlsx) is commonly created
        // first, so sheet1.xml maps to IT, not the data. Resolve the target
        // worksheet part properly: workbook.xml (sheet name -> r:id) +
        // workbook.xml.rels (r:id -> part path). Prefers a sheet literally
        // named "Template" or "Data"; otherwise takes the last sheet
        // (INSTRUCTIONS sheets are conventionally placed first).
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $sheetXml = false;
        if ($workbookXml !== false && $relsXml !== false) {
            $wb = simplexml_load_string($workbookXml);
            $rels = simplexml_load_string($relsXml);
            $targetById = [];
            foreach ($rels->Relationship as $rel) {
                $targetById[(string) $rel['Id']] = ltrim((string) $rel['Target'], '/');
            }
            $ns = $wb->getNamespaces(true);
            $rNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $chosenTarget = null;
            $lastTarget = null;
            foreach ($wb->sheets->sheet as $sheetEl) {
                $attrs = $sheetEl->attributes($rNs);
                $rid = (string) $attrs['id'];
                $target = $targetById[$rid] ?? null;
                if ($target === null) {
                    continue;
                }
                $target = str_starts_with($target, 'xl/') ? $target : ('xl/' . $target);
                $lastTarget = $target;
                if (in_array(strtolower((string) $sheetEl['name']), ['template', 'data'], true)) {
                    $chosenTarget = $target;
                }
            }
            $target = $chosenTarget ?? $lastTarget;
            if ($target !== null) {
                $sheetXml = $zip->getFromName($target);
            }
        }
        if ($sheetXml === false) {
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        }
        $zip->close();
        if ($sheetXml === false) {
            throw new ValidationException(['xlsx file has no readable worksheet']);
        }

        $sheet = simplexml_load_string($sheetXml);
        $grid = [];
        foreach ($sheet->sheetData->row as $rowXml) {
            $rowIndex = (int) $rowXml['r'];
            foreach ($rowXml->c as $cellXml) {
                $ref = (string) $cellXml['r'];
                preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
                $col = $m[1] ?? null;
                if ($col === null) {
                    continue;
                }
                $type = (string) $cellXml['t'];
                if ($type === 'inlineStr') {
                    // <c t="inlineStr"><is><t>text</t></is></c> — no shared-string index,
                    // the text sits directly under is/t (openpyxl writes this form).
                    $value = isset($cellXml->is->t) ? (string) $cellXml->is->t : '';
                } else {
                    $raw = isset($cellXml->v) ? (string) $cellXml->v : '';
                    $value = $type === 's' && $raw !== '' ? ($sharedStrings[(int) $raw] ?? '') : $raw;
                }
                $grid[$rowIndex][$col] = $value;
            }
        }

        if (empty($grid)) {
            return [];
        }
        ksort($grid);
        $rowNumbers = array_keys($grid);
        $headerRowNum = array_shift($rowNumbers);
        $headerRow = $grid[$headerRowNum];
        ksort($headerRow);
        $headers = array_values($headerRow);

        $rows = [];
        foreach ($rowNumbers as $rowNum) {
            $cells = $grid[$rowNum];
            $row = [];
            foreach ($headers as $i => $headerName) {
                $colLetter = self::colLetterAt($i);
                $row[$headerName] = $cells[$colLetter] ?? '';
            }
            // Skip fully-blank trailing rows.
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function colLetterAt(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26);
        }
        return $letter;
    }
}
