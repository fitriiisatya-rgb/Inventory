<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.14 — imports an ACCEPTED reconciliation workbook's "Reconciliation"
 * sheet into warehouse_cutover_lines, verbatim. This is NOT a transaction
 * importer (see ImportOpeningStockService/ImportLiveTransactionService for
 * those) — it never posts a FIFO batch, never touches inventory_batches,
 * and never resolves anything on its own. It only ever:
 *   - preserves source SKU/name/unit and opening/IN/OUT/theoretical
 *     closing figures exactly as the workbook computed them
 *   - preserves PASS/REVIEW/CRITICAL/NO_ACTIVITY, Record Type, Activity
 *     Class verbatim
 *   - derives exception_codes from the workbook's own Notes text (never
 *     invents a code the Notes text doesn't itself contain)
 *   - flags a literal duplicate source SKU row as an exception rather than
 *     silently merging or dropping either occurrence
 * item_id/mapping_status/decision/approved_qty/approved_unit_cost are left
 * at their table defaults (NULL/NOT_FOUND/PENDING) — resolved later by
 * WarehouseCutoverService, never guessed here.
 */
final class WarehouseCutoverImportService
{
    private const REQUIRED_HEADERS = [
        'SKU', 'Name', 'Unit', 'Opening Qty', 'Opening Value', 'IN Qty', 'IN Value',
        'OUT Qty', 'OUT Value', 'Theoretical Closing Qty', 'Theoretical Closing Value',
        'Reconciliation Status',
    ];

    private const VALID_STATUSES = ['PASS', 'REVIEW', 'CRITICAL', 'NO_ACTIVITY'];

    /** Known exception markers this project's reconciliation methodology
     * produces — matched as literal substrings of the workbook's own Notes
     * text, never invented independently of it. */
    private const KNOWN_EXCEPTION_CODES = [
        'NEGATIVE_THEORETICAL_CLOSING',
        'SOURCE_UNIT_CONFLICT',
        'DUPLICATE_MOVEMENT_BALANCE_IMPACT',
        'DUPLICATE_DATA_QUALITY_ONLY',
        'ACTUAL_MOVEMENT_WITHOUT_OPENING',
        'MISSING_PRICE_WITH_ACTUAL_MOVEMENT',
        'SOURCE_NAME_MISMATCH',
        'MASTER_REFERENCE_REVIEW',
    ];

    public static function import(PDO $pdo, int $cutoverId, string $filePath): array
    {
        $cutover = self::loadCutover($pdo, $cutoverId);
        if ($cutover['status'] !== 'DRAFT') {
            throw new ValidationException(["cutover {$cutoverId} is '{$cutover['status']}', not DRAFT — a workbook can only be imported once, into a fresh DRAFT cutover"]);
        }

        $rows = XlsxReaderService::read($filePath, 'Reconciliation');
        if (empty($rows)) {
            throw new ValidationException(['reconciliation workbook has no data rows on the Reconciliation sheet']);
        }
        $headers = array_keys($rows[0]);
        $missing = array_diff(self::REQUIRED_HEADERS, $headers);
        if (!empty($missing)) {
            throw new ValidationException(['Reconciliation sheet is missing required column(s): ' . implode(', ', $missing)]);
        }

        $seenSkus = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['SKU'] ?? ''));
            if ($sku === '') {
                throw new ValidationException(['a data row has a blank SKU — refusing to import (never silently skipped)']);
            }
            $seenSkus[$sku] = ($seenSkus[$sku] ?? 0) + 1;
        }

        $counts = ['PASS' => 0, 'REVIEW' => 0, 'CRITICAL' => 0, 'NO_ACTIVITY' => 0];
        $rowRef = 1; // header occupies row 1 in the source workbook
        $insert = $pdo->prepare(
            'INSERT INTO warehouse_cutover_lines
                (cutover_id, source_sku, source_name, source_unit,
                 opening_qty, opening_value, in_qty, in_value, out_qty, out_value,
                 theoretical_closing_qty, theoretical_closing_value, source_price,
                 reconciliation_status, exception_codes, record_type, activity_class,
                 notes, source_row_reference)
             VALUES
                (:cutover_id, :sku, :name, :unit,
                 :opening_qty, :opening_value, :in_qty, :in_value, :out_qty, :out_value,
                 :tcq, :tcv, :price,
                 :status, :codes, :record_type, :activity_class,
                 :notes, :row_ref)'
        );

        foreach ($rows as $row) {
            $rowRef++;
            $sku = trim((string) ($row['SKU'] ?? ''));
            $status = trim((string) ($row['Reconciliation Status'] ?? ''));
            if (!in_array($status, self::VALID_STATUSES, true)) {
                throw new ValidationException(["row {$rowRef} (SKU {$sku}): unrecognized Reconciliation Status '{$status}' — refusing to import rather than guess"]);
            }
            $counts[$status]++;

            $notes = (string) ($row['Notes'] ?? '');
            $codes = [];
            foreach (self::KNOWN_EXCEPTION_CODES as $code) {
                if (str_contains($notes, $code)) {
                    $codes[] = $code;
                }
            }
            if ($seenSkus[$sku] > 1) {
                // Generic safety net (Section 5): a literal duplicate SKU
                // row in the SOURCE SHEET ITSELF (distinct from an
                // already-resolved-into-one-row movement-file duplicate
                // like 900239/130333, which this workbook's own Notes text
                // already documents via DUPLICATE_*). Every occurrence is
                // still inserted — never merged, never dropped.
                $codes[] = 'DUPLICATE_SOURCE_ROW';
            }

            $tcq = (float) ($row['Theoretical Closing Qty'] ?? 0);
            $tcv = (float) ($row['Theoretical Closing Value'] ?? 0);
            $oq = (float) ($row['Opening Qty'] ?? 0);
            $ov = (float) ($row['Opening Value'] ?? 0);
            // Derived (value/qty of the two verbatim-preserved columns
            // above), never a source column of its own — a convenience for
            // review, not a fabricated source price. NULL when neither
            // basis is usable (qty=0 both places).
            $price = null;
            if ($tcq != 0.0) {
                $price = $tcv / $tcq;
            } elseif ($oq != 0.0) {
                $price = $ov / $oq;
            }

            $insert->execute([
                'cutover_id' => $cutoverId,
                'sku' => $sku,
                'name' => (string) ($row['Name'] ?? ''),
                'unit' => (string) ($row['Unit'] ?? ''),
                'opening_qty' => $oq,
                'opening_value' => $ov,
                'in_qty' => (float) ($row['IN Qty'] ?? 0),
                'in_value' => (float) ($row['IN Value'] ?? 0),
                'out_qty' => (float) ($row['OUT Qty'] ?? 0),
                'out_value' => (float) ($row['OUT Value'] ?? 0),
                'tcq' => $tcq,
                'tcv' => $tcv,
                'price' => $price,
                'status' => $status,
                'codes' => implode(';', $codes),
                'record_type' => (string) ($row['Record Type'] ?? ''),
                'activity_class' => (string) ($row['Activity Class'] ?? ''),
                'notes' => $notes,
                'row_ref' => $rowRef,
            ]);
        }

        $totalRows = count($rows);
        $pdo->prepare(
            'UPDATE warehouse_cutovers
                SET total_rows = :total, pass_rows = :pass, review_rows = :review,
                    critical_rows = :critical, no_activity_rows = :no_activity,
                    status = :status
              WHERE id = :id'
        )->execute([
            'total' => $totalRows,
            'pass' => $counts['PASS'],
            'review' => $counts['REVIEW'],
            'critical' => $counts['CRITICAL'],
            'no_activity' => $counts['NO_ACTIVITY'],
            // VALIDATED = structurally read/inserted successfully. The
            // recomputeStatus() call right below immediately re-derives
            // whether that's enough to also be RECONCILED, or whether any
            // CRITICAL/REVIEW row (every line is decision=PENDING right
            // after a fresh import) pushes it straight to REVIEW_REQUIRED.
            'status' => 'VALIDATED',
            'id' => $cutoverId,
        ]);

        WarehouseCutoverService::recomputeStatus($pdo, $cutoverId);

        return ['total_rows' => $totalRows] + $counts;
    }

    private static function loadCutover(PDO $pdo, int $cutoverId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM warehouse_cutovers WHERE id = :id');
        $stmt->execute(['id' => $cutoverId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException("warehouse cutover {$cutoverId} not found");
        }
        return $row;
    }
}
