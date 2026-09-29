<?php
declare(strict_types=1);

/**
 * Import Stok Sistem (Phase 2 decision: system_qty source = IMPORT for V1).
 *
 * Accepted CSV headers (case-insensitive), one of two qty formats:
 *   sku, system_qty_base, unit_cost                 -- qty already in base unit
 *   sku, qty, unit, unit_cost                        -- qty needs conversion via item's own levels
 *
 * unit_cost is optional; when absent/blank the item's last_buy_price is used.
 * Two-phase: previewCsv() stages rows for review (nothing touches item_stock
 * yet), commit() applies only MATCHED/WARNING rows inside one DB transaction.
 */
final class StockImportService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{batch_id:int, summary:array<string,int>}
     */
    public function previewCsv(int $locationId, string $filePath, string $originalFileName, int $uploadedBy): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Tidak dapat membuka file import.');
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('File kosong atau bukan CSV yang valid.');
        }
        // Strip UTF-8 BOM from the first header cell, if present.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
        $colIndex = array_flip($header);

        $hasBaseQtyCol = isset($colIndex['system_qty_base']);
        $hasQtyUnitCol = isset($colIndex['qty']) && isset($colIndex['unit']);
        if (!$hasBaseQtyCol && !$hasQtyUnitCol) {
            fclose($handle);
            throw new RuntimeException(
                "Header CSV tidak dikenali. Gunakan kolom 'sku,system_qty_base,unit_cost' atau 'sku,qty,unit,unit_cost'."
            );
        }
        if (!isset($colIndex['sku'])) {
            fclose($handle);
            throw new RuntimeException("Kolom 'sku' wajib ada.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO stock_import_batches (location_id, file_name, status, uploaded_by)
                 VALUES (?, ?, \'PREVIEWED\', ?)'
            );
            $stmt->execute([$locationId, $originalFileName, $uploadedBy]);
            $batchId = (int) $this->pdo->lastInsertId();

            $rowStmt = $this->pdo->prepare(
                'INSERT INTO stock_import_rows
                    (batch_id, row_no, raw_sku, raw_qty, raw_unit, raw_unit_cost,
                     item_id, parsed_qty_base, parsed_unit_cost, status, message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $itemStmt = $this->pdo->prepare('SELECT * FROM items WHERE sku = ? LIMIT 1');
            $stockStmt = $this->pdo->prepare('SELECT system_qty FROM item_stock WHERE item_id = ? AND location_id = ? LIMIT 1');

            $summary = [
                'total' => 0, 'matched' => 0, 'warning' => 0,
                'duplicate' => 0, 'sku_not_found' => 0, 'invalid_qty' => 0, 'invalid_unit' => 0,
            ];
            $seenSku = [];
            $rowNumber = 1;

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === 1 && trim((string) $row[0]) === '') {
                    continue; // skip blank lines
                }
                $rowNumber++;
                $summary['total']++;

                $rawSku = trim((string) ($row[$colIndex['sku']] ?? ''));
                $rawQty = $hasBaseQtyCol
                    ? trim((string) ($row[$colIndex['system_qty_base']] ?? ''))
                    : trim((string) ($row[$colIndex['qty']] ?? ''));
                $rawUnit = $hasQtyUnitCol ? trim((string) ($row[$colIndex['unit']] ?? '')) : null;
                $rawCost = isset($colIndex['unit_cost']) ? trim((string) ($row[$colIndex['unit_cost']] ?? '')) : null;

                $status = null;
                $message = null;
                $itemId = null;
                $parsedQtyBase = null;
                $parsedCost = null;

                if ($rawSku === '') {
                    $status = 'SKU_NOT_FOUND';
                    $message = 'SKU kosong pada baris ini.';
                } elseif (isset($seenSku[$rawSku])) {
                    $status = 'DUPLICATE';
                    $message = "SKU '{$rawSku}' muncul lebih dari sekali dalam file ini (baris {$seenSku[$rawSku]} dan {$rowNumber}).";
                } else {
                    $seenSku[$rawSku] = $rowNumber;
                    $itemStmt->execute([$rawSku]);
                    $item = $itemStmt->fetch();

                    if (!$item) {
                        $status = 'SKU_NOT_FOUND';
                        $message = "SKU '{$rawSku}' tidak ditemukan di Master Barang.";
                    } else {
                        $itemId = (int) $item['id'];

                        if ($hasBaseQtyCol) {
                            if (!is_numeric($rawQty) || (float) $rawQty < 0) {
                                $status = 'INVALID_QTY';
                                $message = "Nilai system_qty_base '{$rawQty}' bukan angka valid (>= 0).";
                            } else {
                                $parsedQtyBase = (float) $rawQty;
                            }
                        } else {
                            if (!is_numeric($rawQty) || (float) $rawQty < 0) {
                                $status = 'INVALID_QTY';
                                $message = "Nilai qty '{$rawQty}' bukan angka valid (>= 0).";
                            } elseif (Validation::sameUnit($rawUnit, $item['base_unit'])) {
                                $parsedQtyBase = (float) $rawQty;
                            } elseif (Validation::sameUnit($rawUnit, $item['buy_unit'])) {
                                $parsedQtyBase = (float) $rawQty * (float) $item['buy_content'];
                            } elseif ($item['mid_unit'] !== null && Validation::sameUnit($rawUnit, $item['mid_unit'])) {
                                $parsedQtyBase = (float) $rawQty * UnitConversion::midToBase($item);
                            } else {
                                $status = 'INVALID_UNIT';
                                $message = "Unit '{$rawUnit}' tidak dikenal untuk SKU '{$rawSku}' (harus salah satu dari: {$item['buy_unit']}"
                                    . ($item['mid_unit'] ? ", {$item['mid_unit']}" : '') . ", {$item['base_unit']}).";
                            }
                        }

                        if ($status === null) {
                            // qty resolved; resolve cost and run sanity check
                            if ($rawCost !== null && $rawCost !== '') {
                                if (!is_numeric($rawCost) || (float) $rawCost < 0) {
                                    $status = 'WARNING';
                                    $message = "unit_cost '{$rawCost}' tidak valid, menggunakan last_buy_price Master Barang.";
                                    $parsedCost = (float) $item['last_buy_price'];
                                } else {
                                    $parsedCost = (float) $rawCost;
                                }
                            } else {
                                $parsedCost = (float) $item['last_buy_price'];
                            }

                            if ($status === null) {
                                $stockStmt->execute([$itemId, $locationId]);
                                $existing = $stockStmt->fetch();
                                if ($existing) {
                                    $oldQty = (float) $existing['system_qty'];
                                    $newQty = $parsedQtyBase;
                                    if ($oldQty > 0 && abs($newQty - $oldQty) > $oldQty * 10) {
                                        $status = 'WARNING';
                                        $message = sprintf(
                                            'Perubahan qty sangat besar: %s -> %s (%s %s).',
                                            $oldQty, $newQty, $item['base_unit'], $rawSku
                                        );
                                    }
                                }
                                $status = $status ?? 'MATCHED';
                            }
                        }
                    }
                }

                $summary[strtolower($status)] = ($summary[strtolower($status)] ?? 0) + 1;

                $rowStmt->execute([
                    $batchId, $rowNumber, $rawSku, $rawQty, $rawUnit, $rawCost,
                    $itemId, $parsedQtyBase, $parsedCost, $status, $message,
                ]);
            }

            $this->pdo->prepare(
                'UPDATE stock_import_batches
                    SET total_rows = ?, matched_count = ?, invalid_count = ?, warning_count = ?, duplicate_count = ?
                  WHERE id = ?'
            )->execute([
                $summary['total'],
                $summary['matched'],
                $summary['invalid_qty'] + $summary['invalid_unit'] + $summary['sku_not_found'],
                $summary['warning'],
                $summary['duplicate'],
                $batchId,
            ]);

            $this->pdo->commit();
            fclose($handle);

            return ['batch_id' => $batchId, 'summary' => $summary];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            fclose($handle);
            throw $e;
        }
    }

    /**
     * Commits only MATCHED/WARNING rows of a PREVIEWED batch into item_stock.
     * @return array{committed:int, skipped:int}
     */
    public function commit(int $batchId, int $committedBy): array
    {
        $batchStmt = $this->pdo->prepare('SELECT * FROM stock_import_batches WHERE id = ? LIMIT 1');
        $batchStmt->execute([$batchId]);
        $batch = $batchStmt->fetch();
        if (!$batch) {
            throw new RuntimeException('Batch import tidak ditemukan.');
        }
        if ($batch['status'] !== 'PREVIEWED') {
            throw new RuntimeException("Batch ini sudah berstatus {$batch['status']}, tidak dapat di-commit lagi.");
        }

        $this->pdo->beginTransaction();
        try {
            $rowsStmt = $this->pdo->prepare(
                "SELECT * FROM stock_import_rows WHERE batch_id = ? AND status IN ('MATCHED','WARNING') ORDER BY row_no"
            );
            $rowsStmt->execute([$batchId]);
            $rows = $rowsStmt->fetchAll();

            $findStock = $this->pdo->prepare('SELECT * FROM item_stock WHERE item_id = ? AND location_id = ? LIMIT 1');
            $insertStock = $this->pdo->prepare(
                'INSERT INTO item_stock (item_id, location_id, system_qty, unit_cost, updated_by) VALUES (?, ?, ?, ?, ?)'
            );
            $updateStock = $this->pdo->prepare(
                'UPDATE item_stock SET system_qty = ?, unit_cost = ?, updated_by = ? WHERE id = ?'
            );
            $insertAdjustment = $this->pdo->prepare(
                'INSERT INTO item_stock_adjustments (item_stock_id, old_qty, new_qty, source, import_batch_id, reason, changed_by)
                 VALUES (?, ?, ?, \'IMPORT\', ?, ?, ?)'
            );
            $markCommitted = $this->pdo->prepare('UPDATE stock_import_rows SET committed = 1 WHERE id = ?');

            $committed = 0;
            foreach ($rows as $row) {
                $findStock->execute([$row['item_id'], $batch['location_id']]);
                $existing = $findStock->fetch();
                $oldQty = $existing ? (float) $existing['system_qty'] : 0.0;

                if ($existing) {
                    $updateStock->execute([$row['parsed_qty_base'], $row['parsed_unit_cost'], $committedBy, $existing['id']]);
                    $stockId = (int) $existing['id'];
                } else {
                    $insertStock->execute([$row['item_id'], $batch['location_id'], $row['parsed_qty_base'], $row['parsed_unit_cost'], $committedBy]);
                    $stockId = (int) $this->pdo->lastInsertId();
                }

                $insertAdjustment->execute([
                    $stockId, $oldQty, $row['parsed_qty_base'], $batchId,
                    'Import Stok Sistem batch #' . $batchId, $committedBy,
                ]);
                $markCommitted->execute([$row['id']]);
                $committed++;
            }

            $this->pdo->prepare(
                "UPDATE stock_import_batches SET status = 'COMMITTED', committed_by = ?, committed_at = NOW() WHERE id = ?"
            )->execute([$committedBy, $batchId]);

            $this->pdo->commit();

            Audit::log($committedBy, 'STOCK_IMPORT_COMMIT', 'stock_import_batches', $batchId, null, [
                'committed_rows' => $committed,
                'skipped_rows' => count($rows) === 0 ? 0 : ((int) $batch['total_rows'] - $committed),
            ]);

            return ['committed' => $committed, 'skipped' => (int) $batch['total_rows'] - $committed];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
