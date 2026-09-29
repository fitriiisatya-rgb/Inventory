<?php
declare(strict_types=1);

/**
 * One-time go-live tool: migrate Master Barang and current system stock
 * out of the legacy localStorage app instead of asking the user to
 * re-type everything. The legacy app's actual field names are unknown
 * here (its source isn't available in this environment) so field
 * resolution is alias-based and tolerant of several common spellings —
 * see MASTER_ALIASES / STOCK_ALIASES below.
 *
 * Deliberately reuses the existing, already-audited pipelines instead of
 * writing new ones: Master rows are validated with the exact same
 * UnitConversion::validateConversion() every other item write path uses,
 * and Stock rows are committed by building an in-memory CSV and handing
 * it to StockImportService — the same code that already enforces
 * "missing system stock is never silently 0" (see SessionService's
 * design review corrections 1-4) for every other stock import.
 *
 * Every preview is read-only (no DB writes). commitMaster()/commitStock()
 * re-derive their own validation from the raw rows rather than trusting
 * a client-supplied preview payload, so nothing invalid can be smuggled
 * through by tampering with a prior preview response.
 */
final class LegacyMigrationService
{
    private const MASTER_ALIASES = [
        'sku' => ['sku', 'SKU', 'kode', 'code'],
        'barcode' => ['barcode', 'barCode', 'bar_code'],
        'name' => ['name', 'nama', 'namaBarang', 'nama_barang'],
        'category' => ['category', 'kategori'],
        'brand' => ['merk', 'brand'],
        'distributor' => ['distributor', 'supplier'],
        'buy_unit' => ['buyUnit', 'buy_unit', 'satuanBeli', 'satuan_beli'],
        'buy_content' => ['buyContent', 'buy_content', 'isiBeli', 'isi_beli'],
        'mid_unit' => ['midUnit', 'mid_unit', 'satuanTengah', 'satuan_tengah'],
        'mid_content' => ['midContent', 'mid_content', 'isiTengah', 'isi_tengah'],
        'base_unit' => ['baseUnit', 'base_unit', 'satuanDasar', 'satuan_dasar'],
        'last_buy_price' => ['lastBuyPrice', 'last_buy_price', 'hargaBeli', 'harga_beli', 'harga'],
        'status' => ['status'],
        'note' => ['note', 'catatan', 'keterangan'],
    ];

    private const STOCK_ALIASES = [
        'sku' => ['sku', 'SKU', 'kode', 'code'],
        'location' => ['location', 'warehouse', 'gudang', 'lokasi'],
        'qty' => ['qty', 'currentQty', 'current_qty', 'stok', 'systemQty', 'system_qty'],
        'unit' => ['unit', 'satuan', 'baseUnit', 'base_unit'],
        'unit_cost' => ['unitCost', 'unit_cost', 'cost', 'harga'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    private static function resolve(array $row, array $aliases): array
    {
        // Case-insensitive lookup: build a lowercase-key map of the row once.
        $lower = [];
        foreach ($row as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        $out = [];
        foreach ($aliases as $canonical => $names) {
            $out[$canonical] = null;
            foreach ($names as $name) {
                if (array_key_exists(strtolower($name), $lower) && $lower[strtolower($name)] !== null) {
                    $out[$canonical] = $lower[strtolower($name)];
                    break;
                }
            }
        }
        return $out;
    }

    private static function normalizeStatus(mixed $raw): array
    {
        $s = strtolower(trim((string) $raw));
        if (in_array($s, ['active', 'aktif', ''], true)) {
            return ['ACTIVE', $s === ''];
        }
        if (in_array($s, ['inactive', 'tidak aktif', 'non aktif', 'nonaktif', 'nonactive'], true)) {
            return ['INACTIVE', false];
        }
        return ['ACTIVE', true]; // unrecognized -> default ACTIVE, flagged
    }

    // ------------------------------------------------------------------
    // MASTER
    // ------------------------------------------------------------------

    /**
     * @param array<int,array> $rows decoded legacy master JSON (array of objects)
     * @return array{rows: array<int,array>, summary: array<string,int>, categories: array<int,array>}
     */
    public function previewMaster(array $rows): array
    {
        $existingSkuStmt = $this->pdo->prepare('SELECT id FROM items WHERE sku = ? LIMIT 1');
        $existingCategoryStmt = $this->pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1');

        $seenSku = [];
        $categoriesSeen = [];
        $preview = [];
        $summary = ['total' => 0, 'valid' => 0, 'warning' => 0, 'invalid' => 0, 'duplicate' => 0, 'already_exists' => 0];

        foreach (array_values($rows) as $i => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $rowNo = $i + 1;
            $summary['total']++;
            $f = self::resolve($raw, self::MASTER_ALIASES);
            $issues = [];
            $level = 'VALID';

            $sku = trim((string) ($f['sku'] ?? ''));
            if ($sku === '') {
                $level = 'INVALID';
                $issues[] = 'SKU kosong.';
            } elseif (isset($seenSku[$sku])) {
                $level = 'INVALID';
                $issues[] = "Duplicate SKU dalam file ini (baris {$seenSku[$sku]} dan {$rowNo}).";
                $summary['duplicate']++;
            } else {
                $seenSku[$sku] = $rowNo;
            }

            $name = trim((string) ($f['name'] ?? ''));
            if ($name === '') {
                $level = 'INVALID';
                $issues[] = 'Nama barang kosong.';
            }

            $buyUnit = trim((string) ($f['buy_unit'] ?? ''));
            $buyContent = is_numeric($f['buy_content'] ?? null) ? (float) $f['buy_content'] : null;
            $midUnitRaw = $f['mid_unit'] ?? null;
            $midUnit = $midUnitRaw !== null && trim((string) $midUnitRaw) !== '' ? trim((string) $midUnitRaw) : null;
            $midContent = is_numeric($f['mid_content'] ?? null) ? (float) $f['mid_content'] : null;
            $baseUnit = trim((string) ($f['base_unit'] ?? ''));

            if ($buyUnit === '' || $buyContent === null || $baseUnit === '') {
                $level = 'INVALID';
                $issues[] = 'buyUnit / buyContent / baseUnit wajib diisi dan valid.';
            } else {
                $convIssues = UnitConversion::validateConversion($buyUnit, $buyContent, $midUnit, $midContent, $baseUnit);
                if (UnitConversion::hasBlockingErrors($convIssues)) {
                    $level = 'INVALID';
                } elseif (!empty($convIssues) && $level === 'VALID') {
                    $level = 'WARNING';
                }
                foreach ($convIssues as $ci) {
                    $issues[] = $ci['message'];
                }
            }

            [$status, $statusWarn] = self::normalizeStatus($f['status'] ?? null);
            if ($statusWarn) {
                if ($level === 'VALID') {
                    $level = 'WARNING';
                }
                $issues[] = "Status legacy '" . (string) ($f['status'] ?? '') . "' tidak dikenali, di-default ke ACTIVE.";
            }

            $categoryName = trim((string) ($f['category'] ?? ''));
            if ($categoryName === '') {
                $categoryName = 'Umum';
                if ($level === 'VALID') {
                    $level = 'WARNING';
                }
                $issues[] = 'Kategori kosong pada data legacy — akan menggunakan kategori "Umum".';
            }
            $categoriesSeen[$categoryName] = ($categoriesSeen[$categoryName] ?? 0) + 1;

            $alreadyExists = false;
            if ($sku !== '' && $level !== 'INVALID') {
                $existingSkuStmt->execute([$sku]);
                if ($existingSkuStmt->fetchColumn()) {
                    $alreadyExists = true;
                    if ($level === 'VALID') {
                        $level = 'WARNING';
                    }
                    $issues[] = 'SKU sudah ada di Master Barang saat ini — akan DILEWATI saat commit (data existing tidak ditimpa).';
                    $summary['already_exists']++;
                }
            }

            $lastBuyPrice = is_numeric($f['last_buy_price'] ?? null) ? (float) $f['last_buy_price'] : null;
            $barcode = isset($f['barcode']) && trim((string) $f['barcode']) !== '' ? trim((string) $f['barcode']) : null;

            $preview[] = [
                'row' => $rowNo, 'sku' => $sku, 'name' => $name, 'category' => $categoryName,
                'brand' => isset($f['brand']) ? trim((string) $f['brand']) : null,
                'distributor' => isset($f['distributor']) ? trim((string) $f['distributor']) : null,
                'barcode' => $barcode,
                'buy_unit' => $buyUnit, 'buy_content' => $buyContent,
                'mid_unit' => $midUnit, 'mid_content' => $midContent, 'base_unit' => $baseUnit,
                'last_buy_price' => $lastBuyPrice, 'status' => $status,
                'note' => isset($f['note']) ? trim((string) $f['note']) : null,
                'level' => $level, 'issues' => $issues, 'already_exists' => $alreadyExists,
            ];
            $summary[strtolower($level)]++;
        }

        $categoryPreview = [];
        foreach ($categoriesSeen as $name => $count) {
            $existingCategoryStmt->execute([$name]);
            $categoryPreview[] = ['name' => $name, 'count' => $count, 'exists' => (bool) $existingCategoryStmt->fetchColumn()];
        }

        return ['rows' => $preview, 'summary' => $summary, 'categories' => $categoryPreview];
    }

    /**
     * @return array{created: int, skipped_existing: int, skipped_invalid: int, migration_id: int}
     */
    public function commitMaster(array $rows, int $actorId, string $sourceFile): array
    {
        $preview = $this->previewMaster($rows);

        $this->pdo->beginTransaction();
        try {
            $findCategory = $this->pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1');
            $createCategory = $this->pdo->prepare("INSERT INTO categories (code, name, status) VALUES (?, ?, 'ACTIVE')");
            $categoryIdCache = [];

            $insertItem = $this->pdo->prepare(
                "INSERT INTO items (sku, barcode, name, category_id, brand, distributor,
                    buy_unit, buy_content, mid_unit, mid_content, base_unit, last_buy_price, status, note, migration_source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'LEGACY')"
            );

            $created = 0;
            $skippedExisting = 0;
            $skippedInvalid = 0;

            foreach ($preview['rows'] as $r) {
                if ($r['level'] === 'INVALID') {
                    $skippedInvalid++;
                    continue;
                }
                if ($r['already_exists']) {
                    $skippedExisting++;
                    continue;
                }

                if (!isset($categoryIdCache[$r['category']])) {
                    $findCategory->execute([$r['category']]);
                    $catId = $findCategory->fetchColumn();
                    if (!$catId) {
                        $code = self::generateCategoryCode($r['category']);
                        $createCategory->execute([$code, $r['category']]);
                        $catId = (int) $this->pdo->lastInsertId();
                    }
                    $categoryIdCache[$r['category']] = (int) $catId;
                }

                try {
                    $insertItem->execute([
                        $r['sku'], $r['barcode'], $r['name'], $categoryIdCache[$r['category']],
                        $r['brand'], $r['distributor'], $r['buy_unit'], $r['buy_content'],
                        $r['mid_unit'], $r['mid_content'], $r['base_unit'], $r['last_buy_price'],
                        $r['status'], $r['note'],
                    ]);
                    $created++;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        // Race: SKU inserted by someone else between preview and commit.
                        $skippedExisting++;
                        continue;
                    }
                    throw $e;
                }
            }

            $migStmt = $this->pdo->prepare(
                'INSERT INTO legacy_migrations
                    (type, source_file, imported_by, total_rows, valid_rows, warning_rows, invalid_rows, committed_rows, detail)
                 VALUES (\'MASTER\', ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $migStmt->execute([
                $sourceFile, $actorId, $preview['summary']['total'], $preview['summary']['valid'],
                $preview['summary']['warning'], $preview['summary']['invalid'], $created,
                json_encode(['categories_created' => array_values(array_filter($preview['categories'], fn($c) => !$c['exists']))]),
            ]);
            $migrationId = (int) $this->pdo->lastInsertId();

            Audit::log($actorId, 'LEGACY_MIGRATION_MASTER_COMMIT', 'legacy_migrations', $migrationId, null, [
                'source_file' => $sourceFile, 'created' => $created,
                'skipped_existing' => $skippedExisting, 'skipped_invalid' => $skippedInvalid,
            ]);

            $this->pdo->commit();
            return ['created' => $created, 'skipped_existing' => $skippedExisting, 'skipped_invalid' => $skippedInvalid, 'migration_id' => $migrationId];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function generateCategoryCode(string $name): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
        $base = substr($base !== '' ? $base : 'CAT', 0, 15);
        return $base . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    }

    // ------------------------------------------------------------------
    // STOCK
    // ------------------------------------------------------------------

    /**
     * Read-only. Every row's status is derived against the CURRENT
     * Master Barang (so run this only after commitMaster() has already
     * created the items it needs to match against). Never guesses a
     * conversion: if the legacy row names a unit other than the item's
     * actual base_unit, it's flagged CONVERSION_ERROR and excluded from
     * commit rather than silently converted or defaulted.
     *
     * @return array{rows: array<int,array>, summary: array<string,int>, locations: array<int,array>}
     */
    public function previewStock(array $rows): array
    {
        $itemStmt = $this->pdo->prepare('SELECT * FROM items WHERE sku = ? LIMIT 1');
        $existingLocStmt = $this->pdo->prepare('SELECT id FROM locations WHERE LOWER(name) = LOWER(?) LIMIT 1');

        $locationsSeen = [];
        $preview = [];
        $summary = [
            'total' => 0, 'matched' => 0, 'missing_master' => 0, 'invalid_sku' => 0,
            'invalid_qty' => 0, 'conversion_error' => 0,
        ];

        foreach (array_values($rows) as $i => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $rowNo = $i + 1;
            $summary['total']++;
            $f = self::resolve($raw, self::STOCK_ALIASES);

            $sku = trim((string) ($f['sku'] ?? ''));
            $rawLocation = trim((string) ($f['location'] ?? ''));
            $locationsSeen[$rawLocation] = ($locationsSeen[$rawLocation] ?? 0) + 1;
            $qtyRaw = $f['qty'] ?? null;
            $unitRaw = isset($f['unit']) && trim((string) $f['unit']) !== '' ? trim((string) $f['unit']) : null;
            $costRaw = $f['unit_cost'] ?? null;

            $status = 'MATCHED';
            $message = null;
            $item = null;

            if ($sku === '') {
                $status = 'INVALID_SKU';
                $message = 'SKU kosong.';
            } else {
                $itemStmt->execute([$sku]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    $status = 'MISSING_MASTER';
                    $message = "SKU '{$sku}' belum ada di Master Barang — import Master dulu, atau baris ini akan tetap MISSING_SYSTEM_STOCK untuk SKU tersebut.";
                }
            }

            if ($status === 'MATCHED') {
                if ($qtyRaw === null || $qtyRaw === '' || !is_numeric($qtyRaw) || (float) $qtyRaw < 0) {
                    $status = 'INVALID_QTY';
                    $message = "Qty '" . (string) $qtyRaw . "' bukan angka valid (>= 0).";
                } elseif ($unitRaw !== null && strcasecmp($unitRaw, (string) $item['base_unit']) !== 0) {
                    $status = 'CONVERSION_ERROR';
                    $message = "Unit legacy '{$unitRaw}' berbeda dari base_unit Master '{$item['base_unit']}' — qty TIDAK dikonversi otomatis pada tool ini; perbaiki data legacy atau import manual via CSV.";
                }
            }

            $preview[] = [
                'row' => $rowNo, 'sku' => $sku, 'location' => $rawLocation,
                'qty' => $qtyRaw, 'unit' => $unitRaw, 'unit_cost' => $costRaw,
                'status' => $status, 'message' => $message,
            ];
            $key = strtolower($status);
            $summary[$key] = ($summary[$key] ?? 0) + 1;
        }

        $locationList = [];
        foreach ($locationsSeen as $name => $count) {
            $existingLocStmt->execute([$name]);
            $matchedId = $existingLocStmt->fetchColumn();
            $locationList[] = ['name' => $name, 'count' => $count, 'matched_location_id' => $matchedId ? (int) $matchedId : null];
        }

        return ['rows' => $preview, 'summary' => $summary, 'locations' => $locationList];
    }

    /**
     * @param array<string,int> $locationMap raw legacy location name => target location_id.
     *        Every raw location name that appears among MATCHED rows must have an entry
     *        here (an admin decision made in the UI after previewStock()) or those rows
     *        are silently excluded from commit — never assigned to a guessed location.
     * @return array{by_location: array<int,array>, summary: array<string,int>, migration_id: int}
     */
    public function commitStock(array $rows, array $locationMap, int $actorId, string $sourceFile): array
    {
        $preview = $this->previewStock($rows);

        $byLocation = [];
        $unmapped = 0;
        foreach ($preview['rows'] as $r) {
            if ($r['status'] !== 'MATCHED') {
                continue;
            }
            $locId = $locationMap[$r['location']] ?? null;
            if (!$locId) {
                $unmapped++;
                continue;
            }
            $byLocation[(int) $locId][] = $r;
        }

        $importService = new StockImportService($this->pdo);
        $results = [];
        $totalCommitted = 0;

        foreach ($byLocation as $locId => $rowsForLoc) {
            $csv = "sku,system_qty_base,unit_cost\n";
            foreach ($rowsForLoc as $r) {
                $csv .= self::csvField($r['sku']) . ',' . self::csvField((string) $r['qty']) . ',' . self::csvField($r['unit_cost'] !== null ? (string) $r['unit_cost'] : '') . "\n";
            }
            $tmp = tempnam(sys_get_temp_dir(), 'legacy_stock_');
            file_put_contents($tmp, $csv);
            try {
                $previewResult = $importService->previewCsv($locId, $tmp, $sourceFile, $actorId);
                $commitResult = $importService->commit($previewResult['batch_id'], $actorId);
                $this->pdo->prepare("UPDATE stock_import_batches SET source_type = 'LEGACY' WHERE id = ?")->execute([$previewResult['batch_id']]);
                $totalCommitted += $commitResult['committed'];
                $results[] = [
                    'location_id' => $locId, 'batch_id' => $previewResult['batch_id'],
                    'row_summary' => $previewResult['summary'], 'committed' => $commitResult['committed'],
                ];
            } finally {
                @unlink($tmp);
            }
        }

        $migStmt = $this->pdo->prepare(
            'INSERT INTO legacy_migrations
                (type, source_file, imported_by, total_rows, valid_rows, warning_rows, invalid_rows, committed_rows, detail)
             VALUES (\'STOCK\', ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $migStmt->execute([
            $sourceFile, $actorId, $preview['summary']['total'], $preview['summary']['matched'], 0,
            $preview['summary']['total'] - $preview['summary']['matched'], $totalCommitted,
            json_encode(['batches' => $results, 'unmapped_location_rows' => $unmapped]),
        ]);
        $migrationId = (int) $this->pdo->lastInsertId();

        Audit::log($actorId, 'LEGACY_MIGRATION_STOCK_COMMIT', 'legacy_migrations', $migrationId, null, [
            'source_file' => $sourceFile, 'committed' => $totalCommitted, 'unmapped' => $unmapped,
        ]);

        return ['by_location' => $results, 'summary' => $preview['summary'], 'unmapped' => $unmapped, 'migration_id' => $migrationId];
    }

    private static function csvField(string $v): string
    {
        if (preg_match('/[",\n]/', $v)) {
            return '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }
}
