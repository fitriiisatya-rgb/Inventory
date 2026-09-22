<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.8 — "Import Transaksi Live" (Excel/CSV). Unlike
 * ImportHistoricalTransactionService (is_historical_import=1,
 * inventory_effect=0, raw INSERT, never touches FifoService), every row
 * this commits is a REAL Stock IN or Stock OUT: it calls the real,
 * unmodified FifoService::postIn()/postOut() — same FIFO batches, same
 * fifo_allocations, same stock effect as the manual Transaksi Masuk/Keluar
 * wizard. Only IN and OUT are accepted (V2.8.1) — never
 * TRANSFER/PRODUCTION/OPENING/ADJUSTMENT, which each have their own
 * dedicated posting path and are out of scope here.
 *
 * For an IN row this also reuses the real, unmodified V2.7
 * PurchaseCostingService via PurchaseCostingGateway — the same preview/
 * persist glue public/index.php's own POST /transactions/in route uses
 * (extracted there specifically so this importer could call it too,
 * without depending on any HTTP request having run) — never a second
 * costing implementation. Discount/PPN/freight columns are optional per
 * row and default to NONE/0, exactly like a manual Stock IN that leaves
 * them blank.
 *
 * Idempotency / duplicate protection (V2.8.6) is two layers, both reusing
 * mechanisms that already exist elsewhere in this codebase rather than a
 * bespoke UUID scheme:
 *   1. FILE level: source_file_hash (SHA256 of the uploaded bytes) is
 *      checked against every already-COMMITTED LIVE_TRANSACTION batch at
 *      stage() time (and re-checked at commit() time as a race guard) — a
 *      re-uploaded-and-recommitted identical file is rejected outright.
 *   2. ROW level: transaction_uuid is derived deterministically from
 *      importBatchId + row_no ('LIVE-{batchId}-{rowNo}'), the same
 *      pattern OPENING-{id}-{lineId} and HIST-{batchId}-{rowNo} already
 *      use elsewhere — FifoService::postIn()/postOut() already replay-
 *      protect on this exact value (IdempotencyService), so double-
 *      committing the same batch (blocked by the batch-status check below
 *      anyway) can never create a duplicate transaction either.
 * A CONTENT-based fingerprint (same date+SKU+qty+price) is deliberately
 * NOT used for dedup: two genuinely distinct real purchases/sales on the
 * same day for the same item can legitimately share every field value,
 * and silently merging them would be a false-positive data-loss bug, not
 * a safety feature.
 *
 * Chronological processing (V2.8.6): rows are sorted by transaction_date
 * ASC (row_no ASC as a same-date tiebreaker) before posting, not file
 * order — an OUT dated after an IN in the same file must see that IN's
 * stock, and inventory_batches.received_date (not insertion order) is
 * what Database::lockFifoBatches() consumes FIFO-first anyway.
 *
 * Atomicity (V2.8.7): the caller always wraps commit() in
 * Database::transaction(), same as every other importer's commit() — any
 * exception partway through this loop (insufficient stock, a locked
 * warehouse, a reconciliation failure) rolls back every row already
 * posted in this same commit call. Nothing is ever left half-imported.
 *
 * Expected CSV/XLSX header (see templates/live_transaction template,
 * generated fresh by ImportTemplateService — never hand-maintained):
 * transaction_date, transaction_type, warehouse_code, sku, input_qty,
 * input_unit, unit_price_input, supplier_code, division_code,
 * reference_no, notes, line_discount_type, line_discount_value,
 * invoice_discount_type, invoice_discount_value, ppn_treatment, ppn_rate,
 * ppn_creditable_pct, freight_treatment, freight_amount
 */
final class ImportLiveTransactionService
{
    private const ALLOWED_TYPES = ['IN', 'OUT'];

    public static function stage(PDO $pdo, string $filePath, string $fileName, int $uploadedBy): int
    {
        $rows = str_ends_with(strtolower($fileName), '.xlsx')
            ? XlsxReaderService::read($filePath)
            : self::readCsv($filePath);
        if (empty($rows)) {
            throw new ValidationException(['file has no data rows']);
        }

        $fileHash = hash_file('sha256', $filePath);
        $dup = $pdo->prepare(
            "SELECT id FROM import_batches WHERE import_type = 'LIVE_TRANSACTION' AND source_file_hash = :hash AND status = 'COMMITTED'"
        );
        $dup->execute(['hash' => $fileHash]);
        $dupId = $dup->fetchColumn();
        if ($dupId !== false) {
            throw new ValidationException(["this exact file was already committed as import batch #{$dupId} — re-upload rejected to prevent a duplicate live import"]);
        }

        $batchStmt = $pdo->prepare(
            "INSERT INTO import_batches (import_type, file_name, source_file_hash, status, total_rows, uploaded_by, uploaded_at)
             VALUES ('LIVE_TRANSACTION', :file_name, :hash, 'STAGED', :total, :uploaded_by, :uploaded_at)"
        );
        $batchStmt->execute(['file_name' => $fileName, 'hash' => $fileHash, 'total' => count($rows), 'uploaded_by' => $uploadedBy, 'uploaded_at' => date('Y-m-d H:i:s')]);
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
            return ['ERROR', ['transaction_type must be one of: ' . implode(',', self::ALLOWED_TYPES) . ' (transfer/production/opening/adjustment are not supported by this importer)']];
        }

        $whCode = trim((string) ($row['warehouse_code'] ?? ''));
        // is_active = 1 required, same guard as the V2.6 all-warehouse
        // backend fix — a PENDING_CUTOVER warehouse (e.g. Karang Tengah)
        // can never receive a live-imported transaction.
        $wh = $pdo->prepare('SELECT id FROM warehouses WHERE code = :code AND is_active = 1');
        $wh->execute(['code' => $whCode]);
        if ($wh->fetchColumn() === false) {
            return ['ERROR', ["unknown or inactive warehouse_code: {$whCode}"]];
        }

        $sku = trim((string) ($row['sku'] ?? ''));
        $item = $pdo->prepare('SELECT id FROM items WHERE sku = :sku');
        $item->execute(['sku' => $sku]);
        $itemRow = $item->fetch();
        if (!$itemRow) {
            return ['ERROR', ["unknown SKU: {$sku}"]];
        }

        $qty = $row['input_qty'] ?? '';
        if ($qty === '' || !is_numeric($qty) || (float) $qty <= 0) {
            return ['ERROR', ['input_qty must be a number > 0']];
        }

        $unitCode = strtoupper(trim((string) ($row['input_unit'] ?? '')));
        $unit = $pdo->prepare('SELECT id FROM units WHERE code = :code');
        $unit->execute(['code' => $unitCode]);
        $unitId = $unit->fetchColumn();
        if ($unitId === false) {
            return ['ERROR', ["unknown input_unit: {$unitCode}"]];
        }

        $txDate = trim((string) ($row['transaction_date'] ?? ''));
        if ($txDate === '') {
            return ['ERROR', ['transaction_date is required']];
        }

        if ($type === 'IN') {
            $price = $row['unit_price_input'] ?? '';
            if ($price === '' || !is_numeric($price) || (float) $price <= 0) {
                return ['ERROR', ['unit_price_input must be a number > 0 for an IN row']];
            }
            $supplierCode = trim((string) ($row['supplier_code'] ?? ''));
            if ($supplierCode !== '') {
                $sup = $pdo->prepare('SELECT id FROM suppliers WHERE code = :code');
                $sup->execute(['code' => $supplierCode]);
                if ($sup->fetchColumn() === false) {
                    $messages[] = "unknown supplier_code: {$supplierCode} — row will be posted without a supplier link";
                }
            }
        } else {
            $divisionCode = trim((string) ($row['division_code'] ?? ''));
            if ($divisionCode !== '') {
                $div = $pdo->prepare('SELECT id FROM divisions WHERE code = :code');
                $div->execute(['code' => $divisionCode]);
                if ($div->fetchColumn() === false) {
                    $messages[] = "unknown division_code: {$divisionCode} — row will be posted without a division link";
                }
            }
        }

        $conversion = UnitConversionService::getActiveConversion($pdo, (int) $itemRow['id'], (int) $unitId, $txDate);
        if ($conversion === null) {
            $messages[] = 'no unit conversion found for this item/unit as of transaction_date — commit will fail for this row unless one is approved first';
            return ['WARNING', $messages];
        }

        // Stock sufficiency for an OUT row is inherently order-dependent
        // (it depends on every earlier row in this same batch, processed
        // chronologically) and cannot be known at staging time — it is
        // enforced by FifoService::postOut() itself at commit, exactly as
        // for a manual Transaksi Keluar; an insufficient-stock row simply
        // fails the whole (atomic) commit rather than being pre-flagged
        // here as a false negative or false positive.
        return ['VALID', $messages];
    }

    public static function commit(PDO $pdo, int $importBatchId, int $committedBy): array
    {
        $batch = $pdo->prepare("SELECT * FROM import_batches WHERE id = :id AND import_type = 'LIVE_TRANSACTION'");
        $batch->execute(['id' => $importBatchId]);
        $batch = $batch->fetch();
        if (!$batch) {
            throw new NotFoundException('import batch not found');
        }

        // Double-click / retry protection: this exact batch was already
        // committed — return the same result again rather than re-posting.
        if ($batch['status'] === 'COMMITTED') {
            $already = $pdo->prepare('SELECT COUNT(*) FROM import_rows WHERE import_batch_id = :id AND created_entity_id IS NOT NULL');
            $already->execute(['id' => $importBatchId]);
            return ['imported' => (int) $already->fetchColumn(), 'idempotent_replay' => true];
        }

        if ((int) $batch['error_rows'] > 0) {
            throw new ImportValidationException(['import rejected: batch has ' . $batch['error_rows'] . ' ERROR row(s)']);
        }

        // Race guard: some OTHER batch with the same file hash committed
        // between this batch's stage() and this commit() call.
        if ($batch['source_file_hash'] !== null) {
            $dup = $pdo->prepare(
                "SELECT id FROM import_batches WHERE import_type = 'LIVE_TRANSACTION' AND source_file_hash = :hash AND status = 'COMMITTED' AND id != :self"
            );
            $dup->execute(['hash' => $batch['source_file_hash'], 'self' => $importBatchId]);
            $dupId = $dup->fetchColumn();
            if ($dupId !== false) {
                throw new ImportValidationException(["this exact file was already committed as import batch #{$dupId} — refusing to commit a duplicate"]);
            }
        }

        $committerStmt = $pdo->prepare('SELECT username FROM users WHERE id = :id');
        $committerStmt->execute(['id' => $committedBy]);
        $committerUsername = (string) ($committerStmt->fetchColumn() ?: 'system');

        $rowsStmt = $pdo->prepare(
            "SELECT * FROM import_rows WHERE import_batch_id = :id AND row_status IN ('VALID','WARNING') ORDER BY row_no"
        );
        $rowsStmt->execute(['id' => $importBatchId]);
        $rows = $rowsStmt->fetchAll();

        // Chronological processing (V2.8.6) — not file order.
        usort($rows, static function (array $a, array $b) {
            $dataA = json_decode($a['raw_data'], true);
            $dataB = json_decode($b['raw_data'], true);
            $cmp = strcmp((string) ($dataA['transaction_date'] ?? ''), (string) ($dataB['transaction_date'] ?? ''));
            return $cmp !== 0 ? $cmp : ($a['row_no'] <=> $b['row_no']);
        });

        $created = 0;
        foreach ($rows as $importRow) {
            $data = json_decode($importRow['raw_data'], true);
            $transactionId = self::postRow($pdo, $data, $importBatchId, (int) $importRow['row_no'], $committedBy, $committerUsername);
            $pdo->prepare('UPDATE import_rows SET created_entity_id = :tx WHERE id = :id')
                ->execute(['tx' => $transactionId, 'id' => $importRow['id']]);
            $created++;
        }

        $pdo->prepare('UPDATE import_batches SET status = \'COMMITTED\', committed_by = :by, committed_at = :now WHERE id = :id')
            ->execute(['by' => $committedBy, 'now' => date('Y-m-d H:i:s'), 'id' => $importBatchId]);

        AuditService::log($pdo, $committedBy, $committerUsername, 'LIVE_TRANSACTION_IMPORT', 'import_batches', $importBatchId, null, ['rows_created' => $created], null);

        return ['imported' => $created];
    }

    private static function postRow(PDO $pdo, array $data, int $importBatchId, int $rowNo, int $committedBy, string $committerUsername): int
    {
        $type = strtoupper(trim((string) $data['transaction_type']));

        $whStmt = $pdo->prepare('SELECT id FROM warehouses WHERE code = :code AND is_active = 1');
        $whStmt->execute(['code' => trim((string) $data['warehouse_code'])]);
        $warehouseId = $whStmt->fetchColumn();
        if ($warehouseId === false) {
            throw new ValidationException(["row {$rowNo}: warehouse_code " . $data['warehouse_code'] . ' is unknown or no longer active']);
        }

        $itemStmt = $pdo->prepare('SELECT id FROM items WHERE sku = :sku');
        $itemStmt->execute(['sku' => trim((string) $data['sku'])]);
        $itemId = (int) $itemStmt->fetchColumn();

        $unitStmt = $pdo->prepare('SELECT id FROM units WHERE code = :code');
        $unitStmt->execute(['code' => strtoupper(trim((string) $data['input_unit']))]);
        $unitId = (int) $unitStmt->fetchColumn();

        $transactionUuid = "LIVE-{$importBatchId}-{$rowNo}";

        $common = [
            'transaction_uuid' => $transactionUuid,
            'item_id' => $itemId, 'warehouse_id' => (int) $warehouseId,
            'input_qty' => (float) $data['input_qty'], 'input_unit_id' => $unitId,
            'transaction_date' => $data['transaction_date'],
            'reference_no' => $data['reference_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $committedBy, 'username' => $committerUsername,
        ];

        if ($type === 'IN') {
            $supplierId = null;
            if (!empty($data['supplier_code'])) {
                $supStmt = $pdo->prepare('SELECT id FROM suppliers WHERE code = :code');
                $supStmt->execute(['code' => trim((string) $data['supplier_code'])]);
                $supplierId = $supStmt->fetchColumn() ?: null;
            }

            // Same glue public/index.php's own POST /transactions/in route
            // uses: \inv_purchase_costing_preview() computes the landed
            // cost through the real, unmodified V2.7 PurchaseCostingService,
            // then FifoService::postIn() is called with the resulting
            // equivalent_unit_price_input — FifoService itself never
            // changes. Fields left blank on the row default to NONE/0,
            // same as a manual Stock IN that never opens "Purchase
            // Costing (opsional)".
            $rowInput = $common + [
                'unit_price_input' => (float) $data['unit_price_input'],
                'supplier_id' => $supplierId,
                'line_discount_type' => $data['line_discount_type'] ?? 'NONE',
                'line_discount_value' => (float) ($data['line_discount_value'] ?? 0),
                'invoice_discount_type' => $data['invoice_discount_type'] ?? 'NONE',
                'invoice_discount_value' => (float) ($data['invoice_discount_value'] ?? 0),
                'ppn_treatment' => $data['ppn_treatment'] ?? 'NONE',
                'ppn_rate' => (float) ($data['ppn_rate'] ?? 0),
                'ppn_creditable_pct' => (float) ($data['ppn_creditable_pct'] ?? 0),
                'freight_treatment' => $data['freight_treatment'] ?? 'NONE',
                'freight_amount' => (float) ($data['freight_amount'] ?? 0),
            ];
            $costing = PurchaseCostingGateway::preview($pdo, $rowInput);
            $rowInput['unit_price_input'] = $costing['equivalent_unit_price_input'];

            $posted = FifoService::postIn($pdo, $rowInput);
            if (empty($posted['idempotent_replay'])) {
                PurchaseCostingGateway::persist($pdo, $posted, $committedBy, $costing);
            }
            return (int) $posted['transaction_id'];
        }

        // OUT — division_code is optional metadata only; this importer
        // never sets allow_negative_stock, so an OUT row that would drive
        // stock negative fails commit exactly like a manual Transaksi
        // Keluar would (InsufficientStockException), rolling back the
        // whole (atomic) batch.
        $divisionId = null;
        if (!empty($data['division_code'])) {
            $divStmt = $pdo->prepare('SELECT id FROM divisions WHERE code = :code');
            $divStmt->execute(['code' => trim((string) $data['division_code'])]);
            $divisionId = $divStmt->fetchColumn() ?: null;
        }
        $rowInput = $common + ['division_id' => $divisionId];
        $posted = FifoService::postOut($pdo, $rowInput);
        return (int) $posted['transaction_id'];
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
