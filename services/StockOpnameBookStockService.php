<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.16.1 — "STOK BUKU SO": EOD (End-Of-Day) Stock Opname
 * reconciliation. Revises V2.16's single "Reference SCM" concept into
 * three explicit pieces, all still REFERENCE/RECONCILIATION ONLY (never
 * writes inventory_batches/stock_adjustments/an existing finding/current
 * stock):
 *
 *   A. BASELINE STOK SCM  — importBaseline() + its own stock_opname_
 *      reference_rows (shared table with V2.16's import, batch_kind=
 *      BASELINE), with PER-MOVEMENT-STREAM coverage metadata (the same
 *      baseline file's IN/OUT figures and SCALING figures can genuinely
 *      be current through different dates).
 *   B. MOVEMENT BACKDATE   — importMovements(): a SEPARATE bulk upload
 *      (never merged with the baseline upload) that classifies every row
 *      with an explicit, always-visible inclusion_status — nothing is
 *      ever silently dropped, even an unresolvable or duplicate row.
 *   C. REKONSILIASI EOD    — bookStockEodByItem() / physicalEodByItem()
 *      / reconciliation(): the actual EOD formulas, Sections 7/8/9/10/11
 *      of the spec.
 *
 * THE central business rule this entire file exists to enforce:
 *   a session represents stock as of its OWN session_date, and the
 *   technical cutoff is effective_at < (session_date + 1 day) 00:00:00
 *   [EXCLUSIVE] — never created_at. created_at only ever decides whether
 *   a row is additionally tagged LATE_ENTRY_EOD_SO; it never decides
 *   whether the row counts toward the session's own book/physical stock.
 */
final class StockOpnameBookStockService
{
    private const REQUIRED_MOVEMENT_ALIASES = [
        'effective_at' => ['effective_at', 'effective_date', 'Effective At', 'Effective Date', 'Tanggal Efektif'],
        'document_reference' => ['document_reference', 'document', 'reference', 'Document', 'Reference', 'No Dokumen'],
        'source_code' => ['sku', 'kode_barang', 'SKU', 'Kode Barang', 'Code'],
        'source_name' => ['nama_barang', 'name', 'Nama Barang', 'Name'],
        'movement_type' => ['movement_type', 'type', 'Movement Type', 'Type'],
        'qty' => ['qty', 'quantity', 'Qty', 'Quantity'],
        'unit' => ['unit', 'satuan', 'Unit', 'Satuan'],
    ];

    // ================================================================
    // SO EOD CUTOFF — the technical, non-negotiable boundary. Derived
    // from the session's OWN session_date, never "today" and never
    // created_at. EXCLUSIVE: effective_at must be STRICTLY LESS THAN
    // this instant to belong to this session's book/physical stock.
    // ================================================================
    public static function soEodCutoff(array $session): string
    {
        return date('Y-m-d 00:00:00', strtotime($session['session_date'] . ' +1 day'));
    }

    private static function loadSession(PDO $pdo, int $sessionId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new NotFoundException("opname session {$sessionId} not found");
        }
        return $row;
    }

    // ================================================================
    // A. BASELINE STOK SCM
    // ================================================================

    /**
     * Imports a BASELINE "Stok Buku SO" workbook. Unlike V2.16's generic
     * reference import (a flat header on row 1), the real SCM export's
     * header is NOT on row 1 and spans TWO rows (a group label like
     * "Stok Akhir" merged across its sub-columns "QTY"/"Total Stok" on
     * the row below it) — detectScmHeader() finds it heuristically
     * rather than assuming a fixed row number, and refuses (rather than
     * guesses) when it cannot confidently identify every required
     * column. "Stok Akhir" -> "Total Stok"/"Nilai"/"Rupiah" is NEVER
     * used as the quantity — that is a currency value, not a qty.
     *
     * $coverage = ['inout_through' => 'Y-m-d H:i:s', 'scaling_through' =>
     * ..., 'adjustment_through' => ...] — admin-confirmed AT IMPORT TIME
     * (Section 3: "Admin harus dapat melihat/mengonfirmasi coverage ini
     * saat import"), never inferred from the file itself.
     */
    public static function importBaseline(PDO $pdo, int $sessionId, string $filePath, string $fileName, int $userId, array $coverage): array
    {
        $session = self::loadSession($pdo, $sessionId);

        if (!is_file($filePath)) {
            throw new ValidationException(['uploaded file could not be read']);
        }
        $fileHash = hash_file('sha256', $filePath);
        $dupe = $pdo->prepare("SELECT id, original_filename, uploaded_at FROM stock_opname_reference_batches WHERE session_id = :sid AND file_hash = :hash AND batch_kind = 'BASELINE'");
        $dupe->execute(['sid' => $sessionId, 'hash' => $fileHash]);
        $dupeRow = $dupe->fetch();
        if ($dupeRow) {
            throw new ValidationException([
                "this exact baseline file was already imported into this session as batch #{$dupeRow['id']} ({$dupeRow['original_filename']}, uploaded {$dupeRow['uploaded_at']}) — re-importing an identical file is refused",
            ]);
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $parsed = $ext === 'csv'
            ? ['rows' => self::readCsvRows($filePath), 'detected_sheet' => null, 'parent_header_row' => null, 'subheader_row' => null, 'data_start_row' => 2, 'data_end_row' => null, 'excluded_footer_row_count' => 0]
            : self::readScmWorkbook($filePath);
        $dataRows = $parsed['rows'];
        $sourceRowOffset = $parsed['data_start_row'] - 1;

        if (empty($dataRows)) {
            throw new ValidationException(['baseline file has no data rows']);
        }

        foreach (['inout_through', 'scaling_through', 'adjustment_through'] as $key) {
            if (empty($coverage[$key]) || strtotime($coverage[$key]) === false) {
                throw new ValidationException(["coverage[{$key}] is required and must be a valid date/time — the admin must confirm baseline coverage per movement stream before import"]);
            }
        }

        $counts = ['MATCHED' => 0, 'UNMATCHED_SCM' => 0, 'UNIT_MISMATCH' => 0, 'NEGATIVE_REFERENCE' => 0, 'DUPLICATE' => 0, 'NEEDS_REVIEW' => 0];
        $seenCodes = [];
        $prepared = [];
        $rowRef = $sourceRowOffset;
        foreach ($dataRows as $row) {
            $rowRef++;
            $code = trim((string) ($row['code'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));
            $qtyRaw = trim((string) ($row['qty'] ?? ''));
            if ($code === '') {
                throw new ValidationException(["row {$rowRef}: Kode Barang is blank — refusing to import rather than silently skip"]);
            }
            if ($qtyRaw === '' || !is_numeric($qtyRaw)) {
                throw new ValidationException(["row {$rowRef} (code {$code}): Stok Akhir qty '{$qtyRaw}' is not a valid number"]);
            }
            $qty = (float) $qtyRaw;
            $isDuplicateInFile = isset($seenCodes[$code]);
            $seenCodes[$code] = true;

            $resolved = StockOpnameReferenceImportService::resolveMappingAndUnit($pdo, $sessionId, $code, $unit);
            if ($isDuplicateInFile) {
                $status = 'DUPLICATE';
            } elseif ($qty < 0) {
                $status = 'NEGATIVE_REFERENCE';
            } else {
                $status = $resolved['status'];
            }
            $counts[$status]++;

            $prepared[] = [
                'row_ref' => $rowRef, 'code' => $code, 'name' => $name, 'unit' => $unit, 'qty' => $qty,
                'item_id' => $resolved['item_id'], 'base_unit_code' => $resolved['base_unit_code'],
                'conversion_factor' => $resolved['conversion_factor'],
                'converted_base_qty' => $resolved['conversion_factor'] !== null ? round($qty * $resolved['conversion_factor'], 6) : null,
                'status' => $status,
            ];
        }

        $now = date('Y-m-d H:i:s');
        $insertBatch = $pdo->prepare(
            'INSERT INTO stock_opname_reference_batches
                (session_id, original_filename, file_hash, uploaded_by, uploaded_at,
                 row_count, matched_count, unmatched_count, unit_mismatch_count, negative_count, needs_review_count, duplicate_count, status,
                 batch_kind, baseline_inout_through, baseline_scaling_through, baseline_adjustment_through)
             VALUES (:sid, :fname, :hash, :by, :now,
                     :total, :matched, :unmatched, :unit_mismatch, :negative, :needs_review, :duplicate, \'IMPORTED\',
                     \'BASELINE\', :inout_through, :scaling_through, :adjustment_through)'
        );
        $insertBatch->execute([
            'sid' => $sessionId, 'fname' => $fileName, 'hash' => $fileHash, 'by' => $userId, 'now' => $now,
            'total' => count($prepared), 'matched' => $counts['MATCHED'], 'unmatched' => $counts['UNMATCHED_SCM'],
            'unit_mismatch' => $counts['UNIT_MISMATCH'], 'negative' => $counts['NEGATIVE_REFERENCE'],
            'needs_review' => $counts['NEEDS_REVIEW'], 'duplicate' => $counts['DUPLICATE'],
            'inout_through' => date('Y-m-d H:i:s', strtotime($coverage['inout_through'])),
            'scaling_through' => date('Y-m-d H:i:s', strtotime($coverage['scaling_through'])),
            'adjustment_through' => date('Y-m-d H:i:s', strtotime($coverage['adjustment_through'])),
        ]);
        $batchId = (int) $pdo->lastInsertId();

        $insertRow = $pdo->prepare(
            'INSERT INTO stock_opname_reference_rows
                (session_id, import_batch_id, item_id, source_row_reference, source_code, source_name, source_unit, source_qty,
                 base_unit_code, conversion_factor, converted_base_qty, mapping_status, created_at)
             VALUES (:sid, :batch_id, :item_id, :row_ref, :code, :name, :unit, :qty, :base_unit_code, :factor, :converted, :status, :now)'
        );
        foreach ($prepared as $p) {
            $insertRow->execute([
                'sid' => $sessionId, 'batch_id' => $batchId, 'item_id' => $p['item_id'], 'row_ref' => $p['row_ref'],
                'code' => $p['code'], 'name' => $p['name'], 'unit' => $p['unit'], 'qty' => $p['qty'],
                'base_unit_code' => $p['base_unit_code'], 'factor' => $p['conversion_factor'], 'converted' => $p['converted_base_qty'],
                'status' => $p['status'], 'now' => $now,
            ]);
        }

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_BASELINE_IMPORT', 'stock_opname_reference_batches', $batchId, null, [
            'session_id' => $sessionId, 'row_count' => count($prepared), 'coverage' => $coverage,
        ] + $counts, null);

        return ['import_batch_id' => $batchId, 'row_count' => count($prepared)] + array_change_key_case([
            'matched_count' => $counts['MATCHED'], 'unmatched_count' => $counts['UNMATCHED_SCM'], 'unit_mismatch_count' => $counts['UNIT_MISMATCH'],
            'negative_count' => $counts['NEGATIVE_REFERENCE'], 'needs_review_count' => $counts['NEEDS_REVIEW'], 'duplicate_count' => $counts['DUPLICATE'],
        ]) + [
            // PHASE V2.16.2 — parser diagnostics surfaced to the admin/
            // tests, so a real workbook's header/data-block detection is
            // always visible, never a silent black box.
            'detected_sheet' => $parsed['detected_sheet'], 'parent_header_row' => $parsed['parent_header_row'],
            'subheader_row' => $parsed['subheader_row'], 'data_start_row' => $parsed['data_start_row'],
            'data_end_row' => $parsed['data_end_row'], 'parsed_item_count' => count($dataRows),
            'excluded_footer_row_count' => $parsed['excluded_footer_row_count'],
        ];
    }

    /**
     * Detects the "SCM" sheet and its header, then returns a diagnostic
     * array (never a silent black box — see the keys below) including
     * dataRows keyed by the canonical names code/name/unit/qty — the
     * ORIGINAL cell text is preserved verbatim (never numeric-cast), so
     * a text-stored leading-zero code survives intact.
     *
     * PHASE V2.16.2 — rewritten against the REAL "Inventory September
     * 2026 SCM (gudang besar).xlsx" structure (V2.16.1's version was
     * built from a verbal description only and got the header shape
     * backwards). The real file's actual layout:
     *   Row 7  (PARENT header): No | Nama Barang | Kode Barang | Satuan |
     *           Isi | Harga | Stock Awal | Nominal Stok Awal | Stok Akhir
     *           — this row itself contains "Kode Barang"/"Nama Barang"
     *           literally; it is NOT a leaf row sitting below a group.
     *   Row 8  (SUBHEADER, only under "Stok Akhir"): QTY | Total stok —
     *           a TRUE Excel merge, so "Stok Akhir" text lives only in
     *           its own left-most cell; every other column's row-8 cell
     *           is blank.
     *   Row 9  blank.
     *   Row 10+ real item rows, until the item table ends and footer/
     *           formula rows (garbage "No" values, "#N/A" qty, etc.)
     *           follow — these must NEVER be imported as fake SKUs.
     *
     * Detection, in order:
     *   1. Scan the first 30 rows for one containing both "kode barang"
     *      and "nama barang" (case-insensitive substring) — that is
     *      parent_header_row.
     *   2. If the row directly below it contains a qty-hint word (qty/
     *      quantity/jumlah/total) in any cell, that is subheader_row —
     *      per-column label = parent_header_row's own cell if non-blank,
     *      else (nearest non-blank parent_header_row cell TO THE LEFT) +
     *      " " + subheader_row's own cell at that same column (this is
     *      the correct merge-aware rule: a row-8 cell is only ever
     *      non-blank for an ACTUAL sub-column of a two-row group, so
     *      forward-filling the parent label is only even attempted
     *      where row 8 itself has something to attach it to). If no
     *      qty-hint word appears in the row below, there is no
     *      subheader — every column's label is just its own
     *      parent_header_row cell.
     *   3. data_start_row = the first non-blank row after the header
     *      block (skipping any blank separator rows, e.g. row 9).
     *   4. data_end_row = the last row, scanning forward from
     *      data_start_row, of the CONTIGUOUS run of rows that each have
     *      Kode Barang + Nama Barang + Satuan all non-blank AND a
     *      numeric Stok Akhir QTY. The first row that fails this ends
     *      the item table — everything after it (footer/summary/
     *      formula rows) is counted but NEVER returned as a data row.
     * Ambiguous or zero matches at any step -> ValidationException
     * naming what was found, never a silent guess.
     *
     * @return array{rows: list<array<string,string>>, detected_sheet: string, parent_header_row: int, subheader_row: ?int, data_start_row: int, data_end_row: int, excluded_footer_row_count: int}
     */
    public static function readScmWorkbook(string $filePath): array
    {
        $grid = XlsxReaderService::readRawGrid($filePath, 'SCM');
        if (empty($grid)) {
            throw new ValidationException(['could not read a "SCM" sheet (or any sheet) from this workbook']);
        }
        ksort($grid);
        $rowNumbers = array_keys($grid);
        $maxScan = min(30, end($rowNumbers));

        $parentHeaderRow = null;
        foreach ($rowNumbers as $rowNum) {
            if ($rowNum > $maxScan) {
                break;
            }
            $values = array_map(static fn ($v) => strtolower(trim((string) $v)), $grid[$rowNum]);
            $joined = implode(' | ', $values);
            if (str_contains($joined, 'kode barang') && str_contains($joined, 'nama barang')) {
                $parentHeaderRow = $rowNum;
                break;
            }
        }
        if ($parentHeaderRow === null) {
            throw new ValidationException(['could not detect the header row (expected a row containing both "Kode Barang" and "Nama Barang") in the first 30 rows of the SCM sheet']);
        }

        $parentRow = $grid[$parentHeaderRow] ?? [];
        $qtyHints = ['qty', 'quantity', 'jumlah', 'total'];
        $nextRow = $grid[$parentHeaderRow + 1] ?? [];
        $nextRowJoined = strtolower(implode(' | ', array_map(static fn ($v) => trim((string) $v), $nextRow)));
        $hasSubheader = $nextRowJoined !== '' && array_any($qtyHints, static fn ($hint) => str_contains($nextRowJoined, $hint));
        $subHeaderRow = $hasSubheader ? $parentHeaderRow + 1 : null;
        $subRow = $hasSubheader ? $nextRow : [];

        $allCols = array_unique(array_merge(array_keys($parentRow), array_keys($subRow)));
        usort($allCols, static fn ($a, $b) => XlsxReaderService::colIndexOf($a) <=> XlsxReaderService::colIndexOf($b));
        $lastParentLabel = '';
        $combined = [];
        foreach ($allCols as $col) {
            $parentVal = trim((string) ($parentRow[$col] ?? ''));
            if ($parentVal !== '') {
                $lastParentLabel = $parentVal;
            }
            $subVal = trim((string) ($subRow[$col] ?? ''));
            // A row-8 cell is only ever populated for an actual sub-
            // column of a two-row group (a true Excel merge leaves every
            // non-left-most merged cell blank) — so the parent label is
            // only ever borrowed here, never forward-filled onto a
            // column that has no sub-label of its own.
            $combined[$col] = $subVal !== '' ? strtolower(trim($lastParentLabel . ' ' . $subVal)) : strtolower($parentVal);
        }

        $kodeCol = null;
        $namaCol = null;
        $satuanCol = null;
        $qtyCandidates = [];
        foreach ($combined as $col => $label) {
            if ($kodeCol === null && str_contains($label, 'kode barang')) {
                $kodeCol = $col;
            }
            if ($namaCol === null && str_contains($label, 'nama barang')) {
                $namaCol = $col;
            }
            if ($satuanCol === null && str_contains($label, 'satuan')) {
                $satuanCol = $col;
            }
            if (str_contains($label, 'stok akhir')) {
                $qtyCandidates[$col] = $label;
            }
        }
        if ($kodeCol === null || $namaCol === null || $satuanCol === null) {
            throw new ValidationException(['could not detect Kode Barang/Nama Barang/Satuan columns on the SCM sheet header row', 'found labels: ' . implode('; ', array_values($combined))]);
        }

        $qtyCol = null;
        $valueExclusions = ['total', 'nilai', 'rupiah', ' rp', 'rp.'];
        $qtyOnlyHints = ['qty', 'quantity', 'jumlah'];
        foreach ($qtyCandidates as $col => $label) {
            $isValueColumn = false;
            foreach ($valueExclusions as $ex) {
                if (str_contains($label, $ex)) {
                    $isValueColumn = true;
                    break;
                }
            }
            if ($isValueColumn) {
                continue;
            }
            foreach ($qtyOnlyHints as $hint) {
                if (str_contains($label, $hint)) {
                    $qtyCol = $col;
                    break 2;
                }
            }
        }
        if ($qtyCol === null) {
            // Fall back to a lone "Stok Akhir" candidate once value-type
            // columns are excluded — but only if EXACTLY one remains, never
            // a guess among several.
            $remaining = array_filter($qtyCandidates, function ($label) use ($valueExclusions) {
                foreach ($valueExclusions as $ex) {
                    if (str_contains($label, $ex)) {
                        return false;
                    }
                }
                return true;
            });
            if (count($remaining) === 1) {
                $qtyCol = array_key_first($remaining);
            }
        }
        if ($qtyCol === null) {
            throw new ValidationException(['could not unambiguously identify the "Stok Akhir" QTY column (as opposed to a Total Stok/Nilai/Rupiah value column) on the SCM sheet',
                'candidates found: ' . implode('; ', $qtyCandidates)]);
        }

        $headerBottomRow = $subHeaderRow ?? $parentHeaderRow;

        // data_start_row: the first non-blank row after the header block
        // (skips any blank separator row(s), e.g. row 9 in the real file,
        // without hardcoding a row number).
        $dataStartRow = null;
        foreach ($rowNumbers as $rowNum) {
            if ($rowNum <= $headerBottomRow) {
                continue;
            }
            if (implode('', $grid[$rowNum]) !== '') {
                $dataStartRow = $rowNum;
                break;
            }
        }
        if ($dataStartRow === null) {
            throw new ValidationException(['the SCM sheet has a header but no data rows after it']);
        }

        // data_end_row: the contiguous run of rows, starting at
        // data_start_row, each satisfying ALL of: Kode Barang/Nama
        // Barang/Satuan non-blank AND Stok Akhir QTY numeric. The first
        // row that fails this ends the item table — never silently
        // extended past genuine footer/summary/formula rows.
        $dataRows = [];
        $dataEndRow = null;
        foreach ($rowNumbers as $rowNum) {
            if ($rowNum < $dataStartRow) {
                continue;
            }
            $cells = $grid[$rowNum];
            $code = trim((string) ($cells[$kodeCol] ?? ''));
            $name = trim((string) ($cells[$namaCol] ?? ''));
            $unit = trim((string) ($cells[$satuanCol] ?? ''));
            $qty = trim((string) ($cells[$qtyCol] ?? ''));
            if ($code === '' || $name === '' || $unit === '' || $qty === '' || !is_numeric($qty)) {
                break;
            }
            $dataRows[] = ['code' => $code, 'name' => $name, 'unit' => $unit, 'qty' => $qty];
            $dataEndRow = $rowNum;
        }
        if ($dataEndRow === null) {
            throw new ValidationException(["row {$dataStartRow}: expected the first item row to have Kode Barang/Nama Barang/Satuan all non-blank and a numeric Stok Akhir QTY — found none; refusing to guess where the item table starts"]);
        }

        $excludedFooterRowCount = 0;
        foreach ($rowNumbers as $rowNum) {
            if ($rowNum > $dataEndRow && implode('', $grid[$rowNum]) !== '') {
                $excludedFooterRowCount++;
            }
        }

        return [
            'rows' => $dataRows,
            'detected_sheet' => 'SCM',
            'parent_header_row' => $parentHeaderRow,
            'subheader_row' => $subHeaderRow,
            'data_start_row' => $dataStartRow,
            'data_end_row' => $dataEndRow,
            'excluded_footer_row_count' => $excludedFooterRowCount,
        ];
    }

    // ================================================================
    // B. MOVEMENT BACKDATE — bulk import, every row visible
    // ================================================================

    /**
     * Bulk-imports an IN/OUT/Scaling/Adjustment movement file. Requires
     * at least one BASELINE batch to already exist for this session (the
     * coverage-per-stream cutoffs come from the LATEST baseline batch —
     * without a baseline, "already included in baseline" cannot be
     * evaluated at all). Every row is inserted with an explicit
     * inclusion_status — NOTHING is silently dropped, including a row
     * whose SKU/date/qty could not be resolved at all.
     */
    public static function importMovements(PDO $pdo, int $sessionId, string $filePath, string $fileName, int $userId): array
    {
        $session = self::loadSession($pdo, $sessionId);
        $baseline = self::latestBaselineBatch($pdo, $sessionId);
        if ($baseline === null) {
            throw new ValidationException(['no BASELINE Stok SCM batch exists for this session yet — import the baseline first so movement coverage can be evaluated against its confirmed coverage dates']);
        }
        $cutoff = self::soEodCutoff($session);

        if (!is_file($filePath)) {
            throw new ValidationException(['uploaded file could not be read']);
        }
        $fileHash = hash_file('sha256', $filePath);
        $dupe = $pdo->prepare("SELECT id, original_filename, uploaded_at FROM stock_opname_reference_batches WHERE session_id = :sid AND file_hash = :hash AND batch_kind = 'MOVEMENT'");
        $dupe->execute(['sid' => $sessionId, 'hash' => $fileHash]);
        $dupeRow = $dupe->fetch();
        if ($dupeRow) {
            throw new ValidationException([
                "this exact movement file was already imported into this session as batch #{$dupeRow['id']} ({$dupeRow['original_filename']}, uploaded {$dupeRow['uploaded_at']}) — re-importing an identical file is refused",
            ]);
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $rows = $ext === 'csv' ? self::readCsvRowsAliased($filePath) : self::readAliasedXlsx($filePath);
        if (empty($rows)) {
            throw new ValidationException(['movement file has no data rows']);
        }

        $now = date('Y-m-d H:i:s');
        $counts = ['INCLUDED' => 0, 'ALREADY_IN_BASELINE' => 0, 'AFTER_SO_CUTOFF' => 0, 'UNMATCHED_ITEM' => 0, 'UNIT_MISMATCH' => 0, 'INVALID_DATE' => 0, 'DUPLICATE' => 0, 'NEEDS_REVIEW' => 0];
        $prepared = [];
        $rowRef = 1;
        foreach ($rows as $row) {
            $rowRef++;
            $effRaw = trim((string) ($row['effective_at'] ?? ''));
            $docRef = trim((string) ($row['document_reference'] ?? '')) ?: null;
            $code = trim((string) ($row['source_code'] ?? ''));
            $name = trim((string) ($row['source_name'] ?? ''));
            $typeRaw = strtoupper(trim((string) ($row['movement_type'] ?? '')));
            $qtyRaw = trim((string) ($row['qty'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));
            $reason = "bulk import batch (row {$rowRef})";

            $effectiveAt = null;
            if ($effRaw !== '' && strtotime($effRaw) !== false) {
                $effectiveAt = date('Y-m-d H:i:s', strtotime($effRaw));
            }
            $qty = is_numeric($qtyRaw) ? (float) $qtyRaw : null;
            $validType = in_array($typeRaw, ['IN', 'OUT', 'SCALING', 'ADJUSTMENT'], true);

            $itemId = null;
            $baseUnitCode = null;
            $qtyBase = null;
            $status = null;

            if ($code === '') {
                // a blank SKU can never be resolved to an item — this is an
                // unmatched-item condition, not a date problem, so it gets
                // its own accurate status rather than being lumped into
                // INVALID_DATE.
                $status = 'UNMATCHED_ITEM';
            } elseif ($effectiveAt === null || $qty === null || !$validType) {
                $status = 'INVALID_DATE';
            } else {
                $resolved = StockOpnameReferenceImportService::resolveMappingAndUnit($pdo, $sessionId, $code, $unit);
                $itemId = $resolved['item_id'];
                $baseUnitCode = $resolved['base_unit_code'];
                if ($itemId === null) {
                    $status = 'UNMATCHED_ITEM';
                } elseif ($resolved['conversion_factor'] === null) {
                    $status = 'UNIT_MISMATCH';
                } else {
                    $qtyBase = round($qty * $resolved['conversion_factor'], 6);
                    if ($effectiveAt >= $cutoff) {
                        $status = 'AFTER_SO_CUTOFF';
                    } else {
                        $coverageCutoff = self::coverageCutoffFor($baseline, $typeRaw);
                        if ($coverageCutoff !== null && $effectiveAt <= $coverageCutoff) {
                            $status = 'ALREADY_IN_BASELINE';
                        } else {
                            $status = 'INCLUDED';
                        }
                    }
                }
            }

            $dedupKey = ($itemId !== null && $effectiveAt !== null && $qtyBase !== null)
                ? hash('sha256', implode('|', [$itemId, $effectiveAt, $typeRaw, $qtyBase, $docRef ?? '']))
                : null;
            if ($dedupKey !== null) {
                $existing = $pdo->prepare('SELECT id FROM stock_opname_reference_movements WHERE session_id = :sid AND movement_dedup_key = :k');
                $existing->execute(['sid' => $sessionId, 'k' => $dedupKey]);
                if ($existing->fetchColumn() !== false) {
                    $status = 'DUPLICATE';
                    $dedupKey = null; // never collides with the UNIQUE key of the row already holding it
                }
            }

            $latePreCutoff = ($effectiveAt !== null && $effectiveAt < $cutoff && $now > $cutoff) ? 1 : 0;
            $counts[$status]++;
            $prepared[] = [
                'item_id' => $itemId, 'movement_type' => $validType ? $typeRaw : 'ADJUSTMENT', 'status' => $status,
                'source_code' => $code, 'source_name' => $name, 'source_unit' => $unit, 'source_qty_raw' => $qtyRaw,
                'source_effective_at_raw' => $effRaw, 'dedup_key' => $dedupKey, 'row_ref' => $rowRef,
                'qty_base' => $qtyBase ?? 0.0, 'effective_at' => $effectiveAt, 'document_reference' => $docRef,
                'reason' => $reason, 'late' => $latePreCutoff,
            ];
        }

        $insertBatch = $pdo->prepare(
            "INSERT INTO stock_opname_reference_batches
                (session_id, original_filename, file_hash, uploaded_by, uploaded_at, row_count, status, batch_kind)
             VALUES (:sid, :fname, :hash, :by, :now, :total, 'IMPORTED', 'MOVEMENT')"
        );
        $insertBatch->execute(['sid' => $sessionId, 'fname' => $fileName, 'hash' => $fileHash, 'by' => $userId, 'now' => $now, 'total' => count($prepared)]);
        $batchId = (int) $pdo->lastInsertId();

        $insertMove = $pdo->prepare(
            'INSERT INTO stock_opname_reference_movements
                (session_id, item_id, import_batch_id, movement_type, inclusion_status,
                 source_code, source_name, source_unit, source_qty_raw, source_effective_at_raw,
                 movement_dedup_key, source_row_reference, qty_base, effective_at, document_reference,
                 reason, late_pre_cutoff, created_by, created_at)
             VALUES
                (:sid, :item_id, :batch_id, :type, :status,
                 :code, :name, :unit, :qty_raw, :eff_raw,
                 :dedup, :row_ref, :qty_base, :eff, :doc,
                 :reason, :late, :by, :now)'
        );
        foreach ($prepared as $p) {
            $insertMove->execute([
                'sid' => $sessionId, 'item_id' => $p['item_id'], 'batch_id' => $batchId, 'type' => $p['movement_type'], 'status' => $p['status'],
                'code' => $p['source_code'], 'name' => $p['source_name'], 'unit' => $p['source_unit'], 'qty_raw' => $p['source_qty_raw'], 'eff_raw' => $p['source_effective_at_raw'],
                'dedup' => $p['dedup_key'], 'row_ref' => $p['row_ref'], 'qty_base' => $p['qty_base'], 'eff' => $p['effective_at'], 'doc' => $p['document_reference'],
                'reason' => $p['reason'], 'late' => $p['late'], 'by' => $userId, 'now' => $now,
            ]);
        }

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_MOVEMENT_IMPORT', 'stock_opname_reference_batches', $batchId, null, [
            'session_id' => $sessionId, 'row_count' => count($prepared),
        ] + $counts, null);

        return ['import_batch_id' => $batchId, 'row_count' => count($prepared)] + $counts;
    }

    private static function latestBaselineBatch(PDO $pdo, int $sessionId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM stock_opname_reference_batches WHERE session_id = :sid AND batch_kind = 'BASELINE' ORDER BY id DESC LIMIT 1");
        $stmt->execute(['sid' => $sessionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function coverageCutoffFor(array $baseline, string $movementType): ?string
    {
        return match ($movementType) {
            'IN', 'OUT' => $baseline['baseline_inout_through'],
            'SCALING' => $baseline['baseline_scaling_through'],
            'ADJUSTMENT' => $baseline['baseline_adjustment_through'],
            default => null,
        };
    }

    /**
     * Records exactly ONE movement, explicitly typed/validated — the
     * "quick add" counterpart to the bulk importer above, kept
     * backward-compatible with V2.16's recordMovement() API/tests (same
     * method, now also classifying inclusion_status so a single
     * programmatically-added movement is visible the same way a bulk
     * import's row is).
     */
    public static function recordSingleMovement(PDO $pdo, int $sessionId, int $itemId, string $movementType, float $qtyBase, string $effectiveAt, ?string $documentReference, string $reason, int $userId): int
    {
        return StockOpnameReferenceImportService::recordMovement($pdo, $sessionId, $itemId, $movementType, $qtyBase, $effectiveAt, $documentReference, $reason, $userId);
    }

    // ================================================================
    // C. REKONSILIASI EOD
    // ================================================================

    /**
     * Section 7 — BOOK_STOCK_EOD per item = BASELINE_QTY (latest MATCHED
     * baseline row[s], summed) + eligible IN - eligible OUT +/- eligible
     * SCALING +/- eligible ADJUSTMENT. "Eligible" = inclusion_status =
     * INCLUDED (coverage_cutoff(stream) < effective_at < SO_EOD_CUTOFF —
     * already evaluated and frozen at import time in Section B above).
     *
     * @return array<int, array{item_id:int, baseline_qty:?float, eligible_in:float, eligible_out:float, eligible_scaling:float, eligible_adjustment:float, book_stock_eod:?float}>
     */
    public static function bookStockEodByItem(PDO $pdo, int $sessionId): array
    {
        $refStmt = $pdo->prepare(
            "SELECT r.item_id, SUM(r.converted_base_qty) AS total_baseline
               FROM stock_opname_reference_rows r
               JOIN stock_opname_reference_batches b ON b.id = r.import_batch_id
               WHERE r.session_id = :sid AND r.mapping_status = 'MATCHED' AND r.item_id IS NOT NULL AND b.batch_kind = 'BASELINE'
                 AND r.import_batch_id = (
                     SELECT MAX(r2.import_batch_id) FROM stock_opname_reference_rows r2
                     JOIN stock_opname_reference_batches b2 ON b2.id = r2.import_batch_id
                     WHERE r2.session_id = r.session_id AND r2.item_id = r.item_id AND r2.mapping_status = 'MATCHED' AND b2.batch_kind = 'BASELINE'
                 )
               GROUP BY r.item_id"
        );
        $refStmt->execute(['sid' => $sessionId]);
        $byItem = [];
        foreach ($refStmt->fetchAll() as $r) {
            $itemId = (int) $r['item_id'];
            $byItem[$itemId] = ['item_id' => $itemId, 'baseline_qty' => (float) $r['total_baseline'], 'eligible_in' => 0.0, 'eligible_out' => 0.0, 'eligible_scaling' => 0.0, 'eligible_adjustment' => 0.0];
        }

        $moveStmt = $pdo->prepare("SELECT item_id, movement_type, SUM(qty_base) AS total FROM stock_opname_reference_movements WHERE session_id = :sid AND inclusion_status = 'INCLUDED' GROUP BY item_id, movement_type");
        $moveStmt->execute(['sid' => $sessionId]);
        foreach ($moveStmt->fetchAll() as $m) {
            $itemId = (int) $m['item_id'];
            if (!isset($byItem[$itemId])) {
                $byItem[$itemId] = ['item_id' => $itemId, 'baseline_qty' => null, 'eligible_in' => 0.0, 'eligible_out' => 0.0, 'eligible_scaling' => 0.0, 'eligible_adjustment' => 0.0];
            }
            $total = (float) $m['total'];
            match ($m['movement_type']) {
                'IN' => $byItem[$itemId]['eligible_in'] = $total,
                'OUT' => $byItem[$itemId]['eligible_out'] = $total,
                'SCALING' => $byItem[$itemId]['eligible_scaling'] += $total,
                'ADJUSTMENT' => $byItem[$itemId]['eligible_adjustment'] += $total,
                default => null,
            };
        }

        foreach ($byItem as $itemId => $r) {
            $byItem[$itemId]['book_stock_eod'] = $r['baseline_qty'] === null ? null
                : round($r['baseline_qty'] + $r['eligible_in'] - $r['eligible_out'] + $r['eligible_scaling'] + $r['eligible_adjustment'], 6);
        }
        return $byItem;
    }

    /**
     * Section 8/10/11 — PHYSICAL_EOD. "Post-count movement" uses ONLY
     * inclusion_status=INCLUDED movements (an excluded/duplicate/
     * out-of-window row was never real stock movement for this session
     * in the first place, so it can never contribute here either — no
     * double-counting risk between book stock and physical EOD: they
     * read the SAME included-movements set, through two independent
     * windows, never combined into one number together before being
     * compared as the final variance).
     *
     * @return array{p1: ?array, p2: ?array, final: array{raw_total:float, counted_at:?string, physical_eod:?float}}
     */
    public static function physicalEodForLine(PDO $pdo, int $sessionId, array $line): array
    {
        $cutoff = self::soEodCutoff(self::loadSession($pdo, $sessionId));
        $itemId = (int) $line['item_id'];

        $findingsStmt = $pdo->prepare(
            "SELECT team_role, SUM(finding_good_base_qty + finding_damaged_base_qty + finding_expired_base_qty + finding_deadstock_base_qty) AS raw_total, MIN(counted_at) AS earliest_counted_at, MAX(counted_at) AS latest_counted_at
               FROM stock_opname_findings
              WHERE stock_opname_line_id = :line_id AND voided_at IS NULL
              GROUP BY team_role"
        );
        $findingsStmt->execute(['line_id' => $line['id']]);
        $byRole = [];
        foreach ($findingsStmt->fetchAll() as $f) {
            $byRole[strtolower($f['team_role'])] = ['raw_total' => (float) $f['raw_total'], 'counted_at' => $f['latest_counted_at']];
        }

        $p1 = isset($byRole['p1']) ? self::projectPhysicalEod($pdo, $sessionId, $itemId, $byRole['p1']['raw_total'], $byRole['p1']['counted_at'], $cutoff) : null;
        $p2 = isset($byRole['p2']) ? self::projectPhysicalEod($pdo, $sessionId, $itemId, $byRole['p2']['raw_total'], $byRole['p2']['counted_at'], $cutoff) : null;

        // Final physical, in priority order — never guesses ambiguously:
        //   1. A supervisor recount supersedes both P1/P2 once submitted.
        //   2. Once BOTH sides have been resolved into the line's own
        //      final_* columns (auto-MATCH or supervisor resolveConditions()
        //      — see StockOpnameService::recomputeAggregate()/resolveMatch
        //      Status()), that resolved value is the agreed final result.
        //   3. While only ONE side has counted so far (the ordinary
        //      mid-session state this EOD module is meant to work with —
        //      "Admin masih melakukan input hasil SO"), that one side's own
        //      raw total/counted_at IS the only final value there is yet;
        //      the line's counted_qty_base/final_* columns stay NULL until
        //      resolution, and reading them here would silently produce 0.
        //   4. Both sides counted but NOT YET resolved (a live MISMATCH) —
        //      no single number is defensible yet; final stays null rather
        //      than guessing which side is "right".
        if ($line['recount_qty_base'] !== null && $line['recount_submitted_at'] !== null) {
            $finalRawTotal = (float) $line['recount_qty_base']
                + (float) ($line['final_rusak_qty'] ?? 0) + (float) ($line['final_expired_qty'] ?? 0) + (float) ($line['final_deadstock_qty'] ?? 0);
            $finalCountedAt = $line['recount_submitted_at'];
        } elseif ($line['counted_qty_base'] !== null) {
            $finalRawTotal = (float) $line['counted_qty_base']
                + (float) ($line['final_rusak_qty'] ?? 0) + (float) ($line['final_expired_qty'] ?? 0) + (float) ($line['final_deadstock_qty'] ?? 0);
            $candidates = array_filter([$byRole['p1']['counted_at'] ?? null, $byRole['p2']['counted_at'] ?? null]);
            $finalCountedAt = empty($candidates) ? null : max($candidates);
        } elseif (isset($byRole['p1']) xor isset($byRole['p2'])) {
            $onlyRole = isset($byRole['p1']) ? 'p1' : 'p2';
            $finalRawTotal = $byRole[$onlyRole]['raw_total'];
            $finalCountedAt = $byRole[$onlyRole]['counted_at'];
        } else {
            // Either nobody has counted yet, or both have but disagree and
            // await supervisor resolution — no single final value exists.
            $finalRawTotal = null;
            $finalCountedAt = null;
        }
        $finalProjection = ($finalRawTotal !== null && $finalCountedAt !== null) ? self::projectPhysicalEod($pdo, $sessionId, $itemId, $finalRawTotal, $finalCountedAt, $cutoff) : null;

        return [
            'p1' => $p1, 'p2' => $p2,
            'final' => [
                'raw_total' => $finalRawTotal,
                'counted_at' => $finalCountedAt,
                'physical_eod' => $finalProjection['physical_eod'] ?? ($finalRawTotal !== null && $finalCountedAt === null ? $finalRawTotal : null),
            ],
        ];
    }

    private static function projectPhysicalEod(PDO $pdo, int $sessionId, int $itemId, float $rawTotal, ?string $countedAt, string $cutoff): array
    {
        if ($countedAt === null) {
            return ['raw_total' => $rawTotal, 'counted_at' => null, 'post_count_in' => 0.0, 'post_count_out' => 0.0, 'post_count_adjustment' => 0.0, 'physical_eod' => $rawTotal];
        }
        $stmt = $pdo->prepare(
            "SELECT movement_type, SUM(qty_base) AS total FROM stock_opname_reference_movements
              WHERE session_id = :sid AND item_id = :item AND inclusion_status = 'INCLUDED'
                AND effective_at > :counted_at AND effective_at < :cutoff
              GROUP BY movement_type"
        );
        $stmt->execute(['sid' => $sessionId, 'item' => $itemId, 'counted_at' => $countedAt, 'cutoff' => $cutoff]);
        $in = 0.0;
        $out = 0.0;
        $adj = 0.0;
        foreach ($stmt->fetchAll() as $r) {
            $total = (float) $r['total'];
            match ($r['movement_type']) {
                'IN' => $in = $total,
                'OUT' => $out = $total,
                'SCALING', 'ADJUSTMENT' => $adj += $total,
                default => null,
            };
        }
        return [
            'raw_total' => $rawTotal, 'counted_at' => $countedAt,
            'post_count_in' => $in, 'post_count_out' => $out, 'post_count_adjustment' => $adj,
            'physical_eod' => round($rawTotal + $in - $out + $adj, 6),
        ];
    }

    /**
     * Section 12 — the full per-SKU reconciliation row, combining book
     * stock (A) with physical EOD (C) and the resulting variance.
     */
    public static function reconciliation(PDO $pdo, int $sessionId): array
    {
        $linesStmt = $pdo->prepare(
            'SELECT sol.*, i.sku, i.name, u.code AS base_unit_code, c.name AS category_name
               FROM stock_opname_lines sol
               JOIN items i ON i.id = sol.item_id
               JOIN units u ON u.id = i.base_unit_id
               LEFT JOIN categories c ON c.id = i.category_id
              WHERE sol.session_id = :sid
              ORDER BY i.sku'
        );
        $linesStmt->execute(['sid' => $sessionId]);
        $lines = $linesStmt->fetchAll();

        $bookStock = self::bookStockEodByItem($pdo, $sessionId);
        $rows = [];
        foreach ($lines as $line) {
            $itemId = (int) $line['item_id'];
            $book = $bookStock[$itemId] ?? ['baseline_qty' => null, 'eligible_in' => 0.0, 'eligible_out' => 0.0, 'eligible_scaling' => 0.0, 'eligible_adjustment' => 0.0, 'book_stock_eod' => null];
            $physical = self::physicalEodForLine($pdo, $sessionId, $line);

            $variance = ($book['book_stock_eod'] !== null && $physical['final']['physical_eod'] !== null)
                ? round($physical['final']['physical_eod'] - $book['book_stock_eod'], 6)
                : null;

            $rows[] = [
                'sku' => $line['sku'], 'name' => $line['name'], 'base_unit' => $line['base_unit_code'],
                'baseline_qty' => $book['baseline_qty'], 'eligible_in' => $book['eligible_in'], 'eligible_out' => $book['eligible_out'],
                'eligible_scaling' => $book['eligible_scaling'], 'eligible_adjustment' => $book['eligible_adjustment'],
                'book_stock_eod' => $book['book_stock_eod'],
                'p1' => $physical['p1'], 'p2' => $physical['p2'],
                'recount' => $line['recount_qty_base'] !== null ? (float) $line['recount_qty_base'] : null,
                'final_good' => $line['counted_qty_base'] !== null ? (float) $line['counted_qty_base'] : null,
                'final_damaged' => $line['final_rusak_qty'] !== null ? (float) $line['final_rusak_qty'] : null,
                'final_expired' => $line['final_expired_qty'] !== null ? (float) $line['final_expired_qty'] : null,
                'final_deadstock' => $line['final_deadstock_qty'] !== null ? (float) $line['final_deadstock_qty'] : null,
                'final_total_physical' => $physical['final']['raw_total'],
                'final_physical_eod' => $physical['final']['physical_eod'],
                'variance' => $variance,
            ];
        }
        return $rows;
    }

    // ================================================================
    // File parsing helpers
    // ================================================================

    /** @return list<array<string,string>> */
    private static function readCsvRows(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new ValidationException(['cannot open CSV file']);
        }
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($handle);
            return [];
        }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (implode('', $line) === '') {
                continue;
            }
            $assoc = array_combine($header, array_pad($line, count($header), ''));
            $rows[] = [
                'code' => $assoc['kode barang'] ?? $assoc['kode_barang'] ?? $assoc['sku'] ?? '',
                'name' => $assoc['nama barang'] ?? $assoc['nama_barang'] ?? $assoc['name'] ?? '',
                'unit' => $assoc['satuan'] ?? $assoc['unit'] ?? '',
                'qty' => $assoc['stok akhir'] ?? $assoc['stok_akhir'] ?? $assoc['qty'] ?? '',
            ];
        }
        fclose($handle);
        return $rows;
    }

    /** @return list<array<string,string>> movement rows keyed by the canonical alias names */
    private static function readAliasedXlsx(string $filePath): array
    {
        $raw = XlsxReaderService::read($filePath);
        return self::aliasRows($raw);
    }

    /** @return list<array<string,string>> */
    private static function readCsvRowsAliased(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new ValidationException(['cannot open CSV file']);
        }
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($handle);
            return [];
        }
        $header = array_map('trim', $header);
        $raw = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (implode('', $line) === '') {
                continue;
            }
            $row = [];
            foreach ($header as $i => $h) {
                $row[$h] = $line[$i] ?? '';
            }
            $raw[] = $row;
        }
        fclose($handle);
        return self::aliasRows($raw);
    }

    /** @param list<array<string,string>> $raw @return list<array<string,string>> */
    private static function aliasRows(array $raw): array
    {
        if (empty($raw)) {
            return [];
        }
        $headers = array_keys($raw[0]);
        $colMap = [];
        foreach (self::REQUIRED_MOVEMENT_ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                foreach ($headers as $h) {
                    if (strcasecmp(trim($h), $alias) === 0) {
                        $colMap[$key] = $h;
                        break 2;
                    }
                }
            }
            if (!isset($colMap[$key])) {
                throw new ValidationException(["movement file is missing a required column for '{$key}' (expected one of: " . implode(', ', $aliases) . ')']);
            }
        }
        $out = [];
        foreach ($raw as $row) {
            $mapped = [];
            foreach ($colMap as $key => $h) {
                $mapped[$key] = $row[$h] ?? '';
            }
            $out[] = $mapped;
        }
        return $out;
    }
}
