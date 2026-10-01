<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.16 — Stock Opname Excel/CSV REFERENCE import. REFERENCE/
 * RECONCILIATION ONLY: this class never updates inventory_batches, never
 * touches current stock, never overwrites stock_opname_lines.
 * system_qty_base, never edits an existing P1/P2/recount/finding row, and
 * never creates a stock movement. Its only writes are to the four new
 * stock_opname_reference_* tables added by this same phase.
 *
 * Mapping priority (Section D of the spec): (1) exact item SKU/code
 * match; (2) an approved, ACTIVE legacy-code mapping
 * (stock_opname_reference_item_mappings). Never a fuzzy/name-based
 * automatic match — anything else that cannot be resolved this way is
 * UNMATCHED_SCM, never guessed.
 *
 * Unit conversion (Section E) is resolved ONLY against this session's
 * own FROZEN per-item unit snapshot (StockOpnameService::
 * getSnapshotUnitsForItem() — the same frozen snapshot every other part
 * of this FINDINGS_V1/LEGACY_DUAL_COUNT session already uses), never a
 * live item_unit_conversions lookup and never a conversion invented on
 * the fly. No match in that snapshot for the source unit -> UNIT_MISMATCH.
 */
final class StockOpnameReferenceImportService
{
    private const REQUIRED_ALIASES = [
        'source_code' => ['Source Code', 'source_code', 'Code', 'SKU'],
        'source_name' => ['Source Name', 'source_name', 'Name'],
        'source_unit' => ['Source Unit', 'source_unit', 'Unit'],
        'source_qty' => ['Source Qty', 'source_qty', 'Qty'],
    ];

    /**
     * @return array{import_batch_id:int, row_count:int, matched_count:int, unmatched_count:int, unit_mismatch_count:int, negative_count:int, needs_review_count:int, duplicate_count:int}
     */
    public static function import(PDO $pdo, int $sessionId, string $filePath, string $fileName, int $userId): array
    {
        $session = self::loadSession($pdo, $sessionId);

        if (!is_file($filePath)) {
            throw new ValidationException(['uploaded file could not be read']);
        }
        $fileHash = hash_file('sha256', $filePath);
        if ($fileHash === false) {
            throw new ValidationException(['could not compute file hash']);
        }

        // Section B — whole-file duplicate-upload guard, checked BEFORE any
        // row is read. The UNIQUE (session_id, file_hash) key on
        // stock_opname_reference_batches is the DB-level backstop; this
        // check exists purely to give a clear message instead of a raw
        // constraint-violation error.
        $dupe = $pdo->prepare('SELECT id, original_filename, uploaded_at FROM stock_opname_reference_batches WHERE session_id = :sid AND file_hash = :hash');
        $dupe->execute(['sid' => $sessionId, 'hash' => $fileHash]);
        $dupeRow = $dupe->fetch();
        if ($dupeRow) {
            throw new ValidationException([
                "this exact file was already imported into this session as batch #{$dupeRow['id']} ({$dupeRow['original_filename']}, uploaded {$dupeRow['uploaded_at']}) — re-importing an identical file is refused; upload a corrected file instead",
            ]);
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $rows = $ext === 'csv' ? self::readCsv($filePath) : XlsxReaderService::read($filePath);
        if (empty($rows)) {
            throw new ValidationException(['file has no data rows']);
        }

        $colMap = self::resolveColumns(array_keys($rows[0]));

        $counts = ['MATCHED' => 0, 'UNMATCHED_SCM' => 0, 'UNIT_MISMATCH' => 0, 'NEGATIVE_REFERENCE' => 0, 'DUPLICATE' => 0, 'NEEDS_REVIEW' => 0];
        $seenCodes = [];
        $prepared = [];
        $rowRef = 1; // header occupies row 1 of the source file
        foreach ($rows as $row) {
            $rowRef++;
            $code = trim((string) ($row[$colMap['source_code']] ?? ''));
            $name = trim((string) ($row[$colMap['source_name']] ?? ''));
            $unit = trim((string) ($row[$colMap['source_unit']] ?? ''));
            $qtyRaw = trim((string) ($row[$colMap['source_qty']] ?? ''));
            if ($code === '') {
                throw new ValidationException(["row {$rowRef}: Source Code is blank — refusing to import rather than silently skip"]);
            }
            if ($qtyRaw === '' || !is_numeric($qtyRaw)) {
                throw new ValidationException(["row {$rowRef} (code {$code}): Source Qty '{$qtyRaw}' is not a valid number"]);
            }
            $qty = (float) $qtyRaw;
            $isDuplicateInFile = isset($seenCodes[$code]);
            $seenCodes[$code] = true;

            $resolved = self::resolveRow($pdo, $session, $code, $unit);

            // Priority: DUPLICATE (within this same file) > NEGATIVE_REFERENCE
            // > UNMATCHED_SCM > UNIT_MISMATCH > NEEDS_REVIEW > MATCHED. Data
            // columns (item_id/base_unit_code/conversion_factor/
            // converted_base_qty) are always populated whenever resolvable,
            // independent of which status label ends up reported.
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
                'conversion_factor' => $resolved['conversion_factor'], 'converted_base_qty' => $resolved['conversion_factor'] !== null ? round($qty * $resolved['conversion_factor'], 6) : null,
                'status' => $status,
            ];
        }

        $insertBatch = $pdo->prepare(
            'INSERT INTO stock_opname_reference_batches
                (session_id, original_filename, file_hash, uploaded_by, uploaded_at,
                 row_count, matched_count, unmatched_count, unit_mismatch_count, negative_count, needs_review_count, duplicate_count, status)
             VALUES (:sid, :fname, :hash, :by, :now,
                     :total, :matched, :unmatched, :unit_mismatch, :negative, :needs_review, :duplicate, \'IMPORTED\')'
        );
        $now = date('Y-m-d H:i:s');
        $insertBatch->execute([
            'sid' => $sessionId, 'fname' => $fileName, 'hash' => $fileHash, 'by' => $userId, 'now' => $now,
            'total' => count($prepared), 'matched' => $counts['MATCHED'], 'unmatched' => $counts['UNMATCHED_SCM'],
            'unit_mismatch' => $counts['UNIT_MISMATCH'], 'negative' => $counts['NEGATIVE_REFERENCE'],
            'needs_review' => $counts['NEEDS_REVIEW'], 'duplicate' => $counts['DUPLICATE'],
        ]);
        $batchId = (int) $pdo->lastInsertId();

        $insertRow = $pdo->prepare(
            'INSERT INTO stock_opname_reference_rows
                (session_id, import_batch_id, item_id, source_row_reference, source_code, source_name, source_unit, source_qty,
                 base_unit_code, conversion_factor, converted_base_qty, mapping_status, created_at)
             VALUES
                (:sid, :batch_id, :item_id, :row_ref, :code, :name, :unit, :qty,
                 :base_unit_code, :factor, :converted, :status, :now)'
        );
        foreach ($prepared as $p) {
            $insertRow->execute([
                'sid' => $sessionId, 'batch_id' => $batchId, 'item_id' => $p['item_id'], 'row_ref' => $p['row_ref'],
                'code' => $p['code'], 'name' => $p['name'], 'unit' => $p['unit'], 'qty' => $p['qty'],
                'base_unit_code' => $p['base_unit_code'], 'factor' => $p['conversion_factor'], 'converted' => $p['converted_base_qty'],
                'status' => $p['status'], 'now' => $now,
            ]);
        }

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_REFERENCE_IMPORT', 'stock_opname_reference_batches', $batchId, null, [
            'session_id' => $sessionId, 'row_count' => count($prepared),
        ] + $counts, null);

        return ['import_batch_id' => $batchId, 'row_count' => count($prepared)]
            + ['matched_count' => $counts['MATCHED'], 'unmatched_count' => $counts['UNMATCHED_SCM'], 'unit_mismatch_count' => $counts['UNIT_MISMATCH'],
               'negative_count' => $counts['NEGATIVE_REFERENCE'], 'needs_review_count' => $counts['NEEDS_REVIEW'], 'duplicate_count' => $counts['DUPLICATE']];
    }

    /** @return array{status:string, item_id:?int, base_unit_code:?string, conversion_factor:?float} */
    private static function resolveRow(PDO $pdo, array $session, string $code, string $unit): array
    {
        $itemId = self::matchExactSku($pdo, $code) ?? self::matchLegacyMapping($pdo, $code);
        if ($itemId === null) {
            return ['status' => 'UNMATCHED_SCM', 'item_id' => null, 'base_unit_code' => null, 'conversion_factor' => null];
        }

        try {
            $snapshot = StockOpnameService::getSnapshotUnitsForItem($pdo, (int) $session['id'], $itemId);
        } catch (ValidationException $e) {
            // Item exists and is uniquely identified, but is NOT part of
            // THIS session's scope — never silently matched, never
            // crashes the whole import; flagged for a human to decide.
            return ['status' => 'NEEDS_REVIEW', 'item_id' => $itemId, 'base_unit_code' => null, 'conversion_factor' => null];
        }

        $baseUnitCode = null;
        $factor = null;
        foreach ($snapshot as $u) {
            if ((int) $u['is_base_unit'] === 1) {
                $baseUnitCode = $u['code'];
            }
            if (strcasecmp(trim((string) $u['code']), $unit) === 0) {
                $factor = (float) $u['conversion_to_base'];
            }
        }
        if ($factor === null) {
            // Section E — never invent a conversion the session's own
            // frozen snapshot doesn't already have.
            return ['status' => 'UNIT_MISMATCH', 'item_id' => $itemId, 'base_unit_code' => $baseUnitCode, 'conversion_factor' => null];
        }
        return ['status' => 'MATCHED', 'item_id' => $itemId, 'base_unit_code' => $baseUnitCode, 'conversion_factor' => $factor];
    }

    private static function matchExactSku(PDO $pdo, string $code): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM items WHERE sku = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private static function matchLegacyMapping(PDO $pdo, string $code): ?int
    {
        $stmt = $pdo->prepare('SELECT item_id FROM stock_opname_reference_item_mappings WHERE source_code = :code AND is_active = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Section D — admin-approved, reusable legacy-code -> item mapping.
     * "At most one ACTIVE mapping per source_code" is enforced here (never
     * a DB constraint, same pattern as other soft invariants in this
     * codebase) so an ambiguous double-active-mapping state can never
     * exist in the first place, rather than being detected after the fact.
     */
    public static function approveItemMapping(PDO $pdo, string $code, int $itemId, int $userId, ?string $notes): int
    {
        $code = trim($code);
        if ($code === '') {
            throw new ValidationException(['source code is required']);
        }
        $itemStmt = $pdo->prepare('SELECT id FROM items WHERE id = :id');
        $itemStmt->execute(['id' => $itemId]);
        if ($itemStmt->fetchColumn() === false) {
            throw new NotFoundException("item {$itemId} not found");
        }
        $existing = $pdo->prepare('SELECT id FROM stock_opname_reference_item_mappings WHERE source_code = :code AND is_active = 1');
        $existing->execute(['code' => $code]);
        if ($existing->fetch()) {
            throw new ValidationException(["source code '{$code}' already has an active approved mapping — deactivate it first before approving a different one"]);
        }
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO stock_opname_reference_item_mappings (source_code, item_id, approved_by, approved_at, notes, is_active) VALUES (:code, :item, :by, :now, :notes, 1)')
            ->execute(['code' => $code, 'item' => $itemId, 'by' => $userId, 'now' => $now, 'notes' => $notes]);
        $mappingId = (int) $pdo->lastInsertId();
        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_REFERENCE_MAPPING_APPROVE', 'stock_opname_reference_item_mappings', $mappingId, null, ['source_code' => $code, 'item_id' => $itemId], $notes);
        return $mappingId;
    }

    /**
     * Section D — per-row manual mapping with an audit trail. Re-resolves
     * the unit conversion against the session's frozen snapshot using the
     * NEW item_id (never fuzzy, never guesses a unit the snapshot doesn't
     * have) and stores exactly what resulted, never silently forcing
     * MATCHED when the unit still can't be resolved.
     */
    public static function manualMapRow(PDO $pdo, int $sessionId, int $rowId, int $itemId, int $userId): array
    {
        $rowStmt = $pdo->prepare('SELECT * FROM stock_opname_reference_rows WHERE id = :id AND session_id = :sid');
        $rowStmt->execute(['id' => $rowId, 'sid' => $sessionId]);
        $row = $rowStmt->fetch();
        if (!$row) {
            throw new NotFoundException("reference row {$rowId} not found in session {$sessionId}");
        }
        $itemStmt = $pdo->prepare('SELECT id FROM items WHERE id = :id');
        $itemStmt->execute(['id' => $itemId]);
        if ($itemStmt->fetchColumn() === false) {
            throw new NotFoundException("item {$itemId} not found");
        }

        // Force the SUPERVISOR-CHOSEN item_id rather than re-running the
        // exact/legacy auto-match — that is the entire point of a manual
        // override — but still re-derive the unit conversion against
        // THAT item's own frozen snapshot rather than trusting the old
        // row's stale values.
        $snapshot = [];
        try {
            $snapshot = StockOpnameService::getSnapshotUnitsForItem($pdo, $sessionId, $itemId);
        } catch (ValidationException $e) {
            // falls through with empty snapshot -> UNIT_MISMATCH below
        }
        $baseUnitCode = null;
        $factor = null;
        foreach ($snapshot as $u) {
            if ((int) $u['is_base_unit'] === 1) {
                $baseUnitCode = $u['code'];
            }
            if (strcasecmp(trim((string) $u['code']), $row['source_unit']) === 0) {
                $factor = (float) $u['conversion_to_base'];
            }
        }
        $status = $factor !== null ? 'MATCHED' : 'UNIT_MISMATCH';
        $converted = $factor !== null ? round((float) $row['source_qty'] * $factor, 6) : null;

        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            'UPDATE stock_opname_reference_rows
                SET item_id = :item_id, base_unit_code = :base_unit_code, conversion_factor = :factor,
                    converted_base_qty = :converted, mapping_status = :status, mapped_by = :by, mapped_at = :now
              WHERE id = :id'
        )->execute([
            'item_id' => $itemId, 'base_unit_code' => $baseUnitCode, 'factor' => $factor,
            'converted' => $converted, 'status' => $status, 'by' => $userId, 'now' => $now, 'id' => $rowId,
        ]);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_REFERENCE_ROW_MANUAL_MAP', 'stock_opname_reference_rows', $rowId,
            ['item_id' => $row['item_id'], 'mapping_status' => $row['mapping_status']],
            ['item_id' => $itemId, 'mapping_status' => $status], null);

        return ['row_id' => $rowId, 'item_id' => $itemId, 'mapping_status' => $status, 'converted_base_qty' => $converted];
    }

    /**
     * Section F — late/backdated reference movement. qty_base for IN/OUT
     * must be a positive magnitude (the movement_type alone carries the
     * sign in the SCM_ADJUSTED_REFERENCE formula); SCALING/ADJUSTMENT may
     * be signed. late_pre_cutoff is computed ONCE here, at insert time,
     * from the REAL wall-clock now vs this session's own cutoff
     * (session_date 23:59:59 — the exact same cutoff convention
     * StockOpnameService::post() already uses for its own adjustment's
     * transaction_date).
     */
    public static function recordMovement(PDO $pdo, int $sessionId, int $itemId, string $movementType, float $qtyBase, string $effectiveAt, ?string $documentReference, string $reason, int $userId): int
    {
        if (!in_array($movementType, ['IN', 'OUT', 'SCALING', 'ADJUSTMENT'], true)) {
            throw new ValidationException(['movement_type must be one of IN, OUT, SCALING, ADJUSTMENT']);
        }
        if (in_array($movementType, ['IN', 'OUT'], true) && $qtyBase <= 0) {
            throw new ValidationException(['qty_base must be a positive magnitude for an IN or OUT movement']);
        }
        if ($qtyBase == 0.0) {
            throw new ValidationException(['qty_base must not be zero']);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason is required for every reference movement']);
        }
        $session = self::loadSession($pdo, $sessionId);
        $itemStmt = $pdo->prepare('SELECT id FROM items WHERE id = :id');
        $itemStmt->execute(['id' => $itemId]);
        if ($itemStmt->fetchColumn() === false) {
            throw new NotFoundException("item {$itemId} not found");
        }

        $cutoff = $session['session_date'] . ' 23:59:59';
        $now = date('Y-m-d H:i:s');
        $latePreCutoff = ($effectiveAt <= $cutoff && $now > $cutoff) ? 1 : 0;

        $pdo->prepare(
            'INSERT INTO stock_opname_reference_movements
                (session_id, item_id, movement_type, qty_base, effective_at, document_reference, reason, late_pre_cutoff, created_by, created_at)
             VALUES (:sid, :item, :type, :qty, :eff, :doc, :reason, :late, :by, :now)'
        )->execute([
            'sid' => $sessionId, 'item' => $itemId, 'type' => $movementType, 'qty' => $qtyBase, 'eff' => $effectiveAt,
            'doc' => $documentReference, 'reason' => $reason, 'late' => $latePreCutoff, 'by' => $userId, 'now' => $now,
        ]);
        $movementId = (int) $pdo->lastInsertId();

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_REFERENCE_MOVEMENT_RECORD', 'stock_opname_reference_movements', $movementId, null, [
            'session_id' => $sessionId, 'item_id' => $itemId, 'movement_type' => $movementType, 'qty_base' => $qtyBase,
            'late_pre_cutoff' => $latePreCutoff,
        ], $reason);

        return $movementId;
    }

    /**
     * Section F/G — SCM_ADJUSTED_REFERENCE = SCM_REFERENCE (the latest
     * MATCHED import row for this item in this session) + IN - OUT +/-
     * SCALING/ADJUSTMENT. Computed here, at READ time, from the raw rows —
     * never stored as a column that could silently drift out of sync with
     * its own inputs, and never written back onto stock_opname_lines.
     * system_qty_base (kept as a completely separate concept — Section G).
     *
     * @return array<int, array{item_id:int, scm_reference:?float, in_qty:float, out_qty:float, scaling_adjustment:float, scm_adjusted_reference:?float}> keyed by item_id
     */
    public static function scmAdjustedReferenceByItem(PDO $pdo, int $sessionId): array
    {
        // The latest (highest import_batch_id) MATCHED row(s) per item are
        // the active SCM reference; every earlier batch's rows for that
        // item remain stored untouched for audit, just no longer "active".
        // A batch MAY legitimately carry more than one MATCHED row for the
        // same item (e.g. a manual mapping resolved a second, originally
        // UNMATCHED_SCM row onto an item that already had its own matched
        // row) — those are summed, never silently overwritten by whichever
        // row happens to be fetched last.
        $refStmt = $pdo->prepare(
            'SELECT r.item_id, SUM(r.converted_base_qty) AS total_reference
               FROM stock_opname_reference_rows r
               WHERE r.session_id = :sid AND r.mapping_status = \'MATCHED\' AND r.item_id IS NOT NULL
                 AND r.import_batch_id = (
                     SELECT MAX(r2.import_batch_id) FROM stock_opname_reference_rows r2
                     WHERE r2.session_id = r.session_id AND r2.item_id = r.item_id AND r2.mapping_status = \'MATCHED\'
                 )
               GROUP BY r.item_id'
        );
        $refStmt->execute(['sid' => $sessionId]);
        $byItem = [];
        foreach ($refStmt->fetchAll() as $r) {
            $itemId = (int) $r['item_id'];
            $byItem[$itemId] = ['item_id' => $itemId, 'scm_reference' => (float) $r['total_reference'], 'in_qty' => 0.0, 'out_qty' => 0.0, 'scaling_adjustment' => 0.0];
        }

        $moveStmt = $pdo->prepare('SELECT item_id, movement_type, SUM(qty_base) AS total FROM stock_opname_reference_movements WHERE session_id = :sid GROUP BY item_id, movement_type');
        $moveStmt->execute(['sid' => $sessionId]);
        foreach ($moveStmt->fetchAll() as $m) {
            $itemId = (int) $m['item_id'];
            if (!isset($byItem[$itemId])) {
                $byItem[$itemId] = ['item_id' => $itemId, 'scm_reference' => null, 'in_qty' => 0.0, 'out_qty' => 0.0, 'scaling_adjustment' => 0.0];
            }
            $total = (float) $m['total'];
            if ($m['movement_type'] === 'IN') {
                $byItem[$itemId]['in_qty'] = $total;
            } elseif ($m['movement_type'] === 'OUT') {
                $byItem[$itemId]['out_qty'] = $total;
            } else {
                $byItem[$itemId]['scaling_adjustment'] += $total;
            }
        }

        foreach ($byItem as $itemId => $r) {
            $byItem[$itemId]['scm_adjusted_reference'] = $r['scm_reference'] === null
                ? null
                : round($r['scm_reference'] + $r['in_qty'] - $r['out_qty'] + $r['scaling_adjustment'], 6);
        }
        return $byItem;
    }

    private static function resolveColumns(array $headers): array
    {
        $map = [];
        foreach (self::REQUIRED_ALIASES as $key => $aliases) {
            $found = null;
            foreach ($aliases as $alias) {
                foreach ($headers as $h) {
                    if (strcasecmp(trim($h), $alias) === 0) {
                        $found = $h;
                        break 2;
                    }
                }
            }
            if ($found === null) {
                throw new ValidationException(["file is missing a required column for '{$key}' (expected one of: " . implode(', ', $aliases) . ')']);
            }
            $map[$key] = $found;
        }
        return $map;
    }

    /** @return list<array<string,string>> */
    private static function readCsv(string $filePath): array
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
        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($line === [null] || implode('', $line) === '') {
                continue;
            }
            $row = [];
            foreach ($header as $i => $h) {
                $row[$h] = $line[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
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
}
