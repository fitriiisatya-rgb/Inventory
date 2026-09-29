<?php
declare(strict_types=1);

/**
 * Go-live P0 addition: LegacyMigrationService. Exercises alias-based
 * field resolution (legacy field names are unknown here — its source
 * isn't available in this environment, so the resolver must tolerate
 * several common spellings), status/category normalization, the
 * "never overwrite an existing SKU" rule, unit-conversion validation
 * reuse, and the "never silently default missing stock to 0" invariant
 * carried over from the existing StockImportService pipeline that
 * commitStock() delegates to.
 */
function test_legacy_migration(): void
{
    $pdo = Database::pdo();
    cleanupLegacyFixtures($pdo);

    try {
        $superadminId = ensureLegacyUser($pdo, 'leg_superadmin', 'SUPERADMIN');

        // Pre-existing item to prove "already exists -> skip, never overwrite".
        $pdo->prepare("INSERT INTO categories (code, name, status) VALUES ('LEG-CAT-PRE','Sembako','ACTIVE')")->execute();
        $preCatId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO items (sku, name, category_id, buy_unit, buy_content, base_unit, last_buy_price, status)
             VALUES ('LEG-EXIST', 'Original Name (do not overwrite)', ?, 'Pcs', 1, 'Pcs', 999, 'ACTIVE')"
        )->execute([$preCatId]);

        $legacy = new LegacyMigrationService($pdo);

        // ------------------------------------------------------------
        T::section('LegacyMigrationService — previewMaster() field alias resolution + validation levels');
        // ------------------------------------------------------------

        $masterRows = [
            ['sku' => 'LEG-A', 'nama' => 'Item Legacy A', 'kategori' => 'Sembako', 'buyUnit' => 'Karton', 'buyContent' => 20000, 'midUnit' => 'Kg', 'midContent' => 20, 'baseUnit' => 'Gr', 'hargaBeli' => 15000, 'status' => 'Aktif'],
            ['SKU' => 'LEG-B', 'name' => 'Item Legacy B', 'category' => 'Sembako', 'buy_unit' => 'Pcs', 'buy_content' => 1, 'base_unit' => 'Pcs', 'status' => 'Tidak Aktif'],
            ['sku' => 'LEG-C', 'name' => '', 'kategori' => 'X', 'buyUnit' => 'Pcs', 'buyContent' => 1, 'baseUnit' => 'Pcs'],
            ['sku' => 'LEG-A', 'name' => 'Duplicate of A', 'buyUnit' => 'Pcs', 'buyContent' => 1, 'baseUnit' => 'Pcs'],
            ['sku' => 'LEG-D', 'name' => 'Item D (content swapped)', 'buyUnit' => 'Karton', 'buyContent' => 100, 'midUnit' => 'Pak', 'midContent' => 200, 'baseUnit' => 'Pcs'],
            ['sku' => 'LEG-E', 'name' => 'Item E (no category)', 'buyUnit' => 'Pcs', 'buyContent' => 1, 'baseUnit' => 'Pcs'],
            ['sku' => 'LEG-F', 'name' => 'Item F (weird status)', 'buyUnit' => 'Pcs', 'buyContent' => 1, 'baseUnit' => 'Pcs', 'status' => 'Random Value'],
            ['sku' => 'LEG-EXIST', 'name' => 'Legacy version of existing item', 'buyUnit' => 'Pcs', 'buyContent' => 1, 'baseUnit' => 'Pcs'],
            // Real pattern observed in an actual legacy export: a 1-level
            // item (buy_unit == base_unit) still carrying a vestigial
            // mid_unit equal to itself — must be normalized away, not
            // rejected as INVALID.
            ['sku' => 'LEG-G', 'name' => 'Item G (vestigial mid=buy=base)', 'buyUnit' => 'Pack', 'buyContent' => 1, 'midUnit' => 'Pack', 'midContent' => 1, 'baseUnit' => 'Pack'],
            // A 2-level item whose mid_unit happens to equal its base_unit —
            // also vestigial, also must be normalized away.
            ['sku' => 'LEG-H', 'name' => 'Item H (vestigial mid=base)', 'buyUnit' => 'Dus', 'buyContent' => 10, 'midUnit' => 'Pcs', 'midContent' => 10, 'baseUnit' => 'Pcs'],
        ];

        $previewM = $legacy->previewMaster($masterRows);
        $bySku = [];
        foreach ($previewM['rows'] as $r) {
            $bySku[$r['sku'] . '#' . $r['row']] = $r;
        }

        T::assertEquals(10, $previewM['summary']['total'], 'previewMaster() sees all 10 rows');
        $rowA = $previewM['rows'][0];
        T::assertEquals('VALID', $rowA['level'], 'LEG-A (3-level, all fields present) -> VALID');
        T::assertEquals('Item Legacy A', $rowA['name'], "alias 'nama' resolved to name");
        T::assertEquals(20000.0, $rowA['buy_content'], "alias 'buyContent' resolved correctly");
        T::assertEquals(15000.0, $rowA['last_buy_price'], "alias 'hargaBeli' resolved to last_buy_price");
        T::assertEquals('ACTIVE', $rowA['status'], "'Aktif' normalized to ACTIVE");

        $rowB = $previewM['rows'][1];
        T::assertEquals('VALID', $rowB['level'], 'LEG-B (1-level, buy_unit=base_unit, buy_content=1) -> VALID');
        T::assertEquals('INACTIVE', $rowB['status'], "'Tidak Aktif' normalized to INACTIVE");

        $rowC = $previewM['rows'][2];
        T::assertEquals('INVALID', $rowC['level'], 'LEG-C empty name -> INVALID');

        $rowADup = $previewM['rows'][3];
        T::assertEquals('INVALID', $rowADup['level'], 'Second LEG-A row -> INVALID (duplicate SKU within the file)');
        T::assertTrue(str_contains(implode(' ', $rowADup['issues']), 'Duplicate SKU'), 'Duplicate issue message present');

        $rowD = $previewM['rows'][4];
        T::assertEquals('WARNING', $rowD['level'], 'LEG-D mid_content > buy_content -> WARNING (possible swap), not INVALID');

        $rowE = $previewM['rows'][5];
        T::assertEquals('WARNING', $rowE['level'], 'LEG-E missing category -> WARNING');
        T::assertEquals('Umum', $rowE['category'], 'Missing category defaults to "Umum"');

        $rowF = $previewM['rows'][6];
        T::assertEquals('WARNING', $rowF['level'], 'LEG-F unrecognized status string -> WARNING');
        T::assertEquals('ACTIVE', $rowF['status'], 'Unrecognized status defaults to ACTIVE');

        $rowExist = $previewM['rows'][7];
        T::assertEquals('WARNING', $rowExist['level'], 'LEG-EXIST (already in Master Barang) -> WARNING, not INVALID');
        T::assertTrue($rowExist['already_exists'], 'already_exists flag set for LEG-EXIST');

        $rowG = $previewM['rows'][8];
        T::assertEquals('WARNING', $rowG['level'], 'LEG-G vestigial mid_unit==buy_unit==base_unit -> normalized away, WARNING not INVALID');
        T::assertTrue($rowG['mid_unit'] === null, 'LEG-G mid_unit dropped to null after normalization');
        T::assertTrue(str_contains(implode(' ', $rowG['issues']), 'dihapus otomatis'), 'LEG-G issue explains the auto-drop');

        $rowH = $previewM['rows'][9];
        T::assertEquals('WARNING', $rowH['level'], 'LEG-H vestigial mid_unit==base_unit -> normalized away, WARNING not INVALID');
        T::assertTrue($rowH['mid_unit'] === null, 'LEG-H mid_unit dropped to null after normalization');

        $catNames = array_column($previewM['categories'], 'name');
        T::assertTrue(in_array('Sembako', $catNames, true), 'Sembako appears in category preview (already exists)');
        T::assertTrue(in_array('Umum', $catNames, true), 'Umum (auto-fallback) appears in category preview');
        $sembakoPreview = $previewM['categories'][array_search('Sembako', $catNames, true)];
        T::assertTrue($sembakoPreview['exists'], 'Sembako correctly flagged as an existing category (pre-seeded)');
        $umumPreview = $previewM['categories'][array_search('Umum', $catNames, true)];
        T::assertFalse($umumPreview['exists'], 'Umum correctly flagged as NOT existing yet (will be auto-created)');

        // ------------------------------------------------------------
        T::section('LegacyMigrationService — commitMaster() never overwrites, skips INVALID, creates categories');
        // ------------------------------------------------------------

        $commitM = $legacy->commitMaster($masterRows, $superadminId, 'legacy_master_test.json');
        // Valid+Warning rows that aren't already_exists: A, B, D, E, F, G, H = 7. C and duplicate-A are INVALID (2). LEG-EXIST is skipped-existing (1).
        T::assertEquals(7, $commitM['created'], 'Exactly 7 new items created (A, B, D, E, F, G, H)');
        T::assertEquals(1, $commitM['skipped_existing'], 'LEG-EXIST skipped as already-existing');
        T::assertEquals(2, $commitM['skipped_invalid'], 'LEG-C and duplicate LEG-A skipped as invalid');

        $itemG = $pdo->prepare('SELECT * FROM items WHERE sku = ?');
        $itemG->execute(['LEG-G']);
        $legG = $itemG->fetch();
        T::assertTrue($legG !== false, 'LEG-G was actually inserted despite its vestigial legacy mid_unit');
        T::assertTrue($legG['mid_unit'] === null, 'LEG-G persisted with mid_unit=NULL (correctly normalized to a 1-level item)');

        $existRow = $pdo->prepare('SELECT * FROM items WHERE sku = ?');
        $existRow->execute(['LEG-EXIST']);
        $existData = $existRow->fetch();
        T::assertEquals('Original Name (do not overwrite)', $existData['name'], 'Pre-existing LEG-EXIST name was NOT overwritten by the legacy row');
        T::assertEquals('MANUAL', $existData['migration_source'], 'Pre-existing item keeps migration_source=MANUAL (untouched)');

        $newItem = $pdo->prepare('SELECT * FROM items WHERE sku = ?');
        $newItem->execute(['LEG-A']);
        $legA = $newItem->fetch();
        T::assertTrue($legA !== false, 'LEG-A was actually inserted');
        T::assertEquals('LEGACY', $legA['migration_source'], 'Newly migrated item tagged migration_source=LEGACY');
        T::assertEquals(20000.0, (float) $legA['buy_content'], 'buy_content correctly persisted (legacy semantics unchanged: base units per 1 buy unit)');
        T::assertEquals(20.0, (float) $legA['mid_content'], 'mid_content correctly persisted (MID units per 1 buy unit, never reinterpreted)');

        $newCatStmt = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
        $newCatStmt->execute(['Umum']);
        T::assertTrue($newCatStmt->fetchColumn() !== false, 'Category "Umum" was auto-created');

        $migRow = $pdo->prepare('SELECT * FROM legacy_migrations WHERE id = ?');
        $migRow->execute([$commitM['migration_id']]);
        $migData = $migRow->fetch();
        T::assertEquals('MASTER', $migData['type'], 'legacy_migrations row recorded with type=MASTER');
        T::assertEquals(7, (int) $migData['committed_rows'], 'legacy_migrations.committed_rows matches created count');

        $auditCheck = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'LEGACY_MIGRATION_MASTER_COMMIT' AND entity_id = ?");
        $auditCheck->execute([$commitM['migration_id']]);
        T::assertTrue((int) $auditCheck->fetchColumn() >= 1, 'Master migration commit is audit logged');

        // ------------------------------------------------------------
        T::section('LegacyMigrationService — previewStock() matching, missing master, conversion errors');
        // ------------------------------------------------------------

        $pdo->prepare("INSERT INTO locations (code, name, status) VALUES ('LEG-LOC1','Gudang Utama Legacy','ACTIVE')")->execute();
        $loc1Id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO locations (code, name, status) VALUES ('LEG-LOC2','Gudang Transit Legacy','ACTIVE')")->execute();
        $loc2Id = (int) $pdo->lastInsertId();

        $stockRows = [
            ['sku' => 'LEG-A', 'location' => 'Gudang Utama', 'qty' => 1000, 'unit' => 'Gr', 'unitCost' => 15],
            ['sku' => 'LEG-B', 'warehouse' => 'Gudang Utama', 'currentQty' => 50],
            ['sku' => 'LEG-NOTFOUND', 'location' => 'Gudang Utama', 'qty' => 10],
            ['sku' => 'LEG-A', 'location' => 'Gudang Utama', 'qty' => 'abc'],
            ['sku' => 'LEG-A', 'location' => 'Gudang Transit', 'qty' => 5, 'unit' => 'Kg'],
        ];

        $previewS = $legacy->previewStock($stockRows);
        T::assertEquals(5, $previewS['summary']['total'], 'previewStock() sees all 5 rows');
        T::assertEquals(2, $previewS['summary']['matched'], 'Exactly 2 rows MATCHED (LEG-A qty=1000 unit=Gr, LEG-B qty=50 no unit)');
        T::assertEquals(1, $previewS['summary']['missing_master'], 'LEG-NOTFOUND -> MISSING_MASTER');
        T::assertEquals(1, $previewS['summary']['invalid_qty'], "qty='abc' -> INVALID_QTY");
        T::assertEquals(1, $previewS['summary']['conversion_error'], 'unit=Kg != base_unit Gr -> CONVERSION_ERROR, never auto-converted');

        $locNames = array_column($previewS['locations'], 'name');
        T::assertTrue(in_array('Gudang Utama', $locNames, true), 'Raw legacy location "Gudang Utama" surfaced for mapping');
        T::assertTrue(in_array('Gudang Transit', $locNames, true), 'Raw legacy location "Gudang Transit" surfaced for mapping');
        $utamaEntry = $previewS['locations'][array_search('Gudang Utama', $locNames, true)];
        T::assertEquals(4, $utamaEntry['count'], '"Gudang Utama" appears in 4 rows (1,2,3,4)');
        // "Gudang Utama" happens to exactly name-match a real seeded location
        // (code=MAIN) — the preview surfaces that as a convenience pre-fill
        // for the mapping step, it's still the admin's call to confirm it.
        T::assertTrue($utamaEntry['matched_location_id'] !== null, '"Gudang Utama" convenience-matched to the existing same-named location as a pre-fill suggestion');
        $transitEntry = $previewS['locations'][array_search('Gudang Transit', $locNames, true)];
        T::assertTrue($transitEntry['matched_location_id'] === null, '"Gudang Transit" has no same-named existing location — no fuzzy guess, left for explicit admin mapping');

        // ------------------------------------------------------------
        T::section('LegacyMigrationService — commitStock() reuses StockImportService, never defaults missing stock to 0');
        // ------------------------------------------------------------

        $commitS = $legacy->commitStock($stockRows, ['Gudang Utama' => $loc1Id, 'Gudang Transit' => $loc2Id], $superadminId, 'legacy_stock_test.json');
        T::assertEquals(1, count($commitS['by_location']), 'Only Gudang Utama got a batch — Gudang Transit had 0 MATCHED rows, so no empty batch was created');
        T::assertEquals($loc1Id, $commitS['by_location'][0]['location_id'], 'Batch created for the correct mapped location_id');
        T::assertEquals(2, $commitS['by_location'][0]['committed'], '2 rows committed (LEG-A, LEG-B)');

        $batchStmt = $pdo->prepare('SELECT * FROM stock_import_batches WHERE id = ?');
        $batchStmt->execute([$commitS['by_location'][0]['batch_id']]);
        $batchData = $batchStmt->fetch();
        T::assertEquals('LEGACY', $batchData['source_type'], 'Committed batch tagged source_type=LEGACY for session traceability');
        T::assertEquals('COMMITTED', $batchData['status'], 'Batch reaches COMMITTED status via the existing StockImportService::commit() path');

        $stockCheck = $pdo->prepare('SELECT s.* FROM item_stock s JOIN items i ON i.id = s.item_id WHERE i.sku = ? AND s.location_id = ?');
        $stockCheck->execute(['LEG-A', $loc1Id]);
        $legAStock = $stockCheck->fetch();
        T::assertEquals(1000.0, (float) $legAStock['system_qty'], 'LEG-A system_qty = 1000 (base unit Gr, no conversion needed — legacy already reported base unit)');
        T::assertEquals(15.0, (float) $legAStock['unit_cost'], 'LEG-A unit_cost = 15 from legacy unitCost');

        $stockCheck->execute(['LEG-B', $loc1Id]);
        $legBStock = $stockCheck->fetch();
        T::assertEquals(50.0, (float) $legBStock['system_qty'], 'LEG-B system_qty = 50');
        T::assertTrue($legBStock['unit_cost'] === null, 'LEG-B unit_cost is NULL — legacy row had none AND Master has no last_buy_price, never coerced to 0');

        // LEG-D exists in Master but was never mentioned in the stock JSON at
        // all — the critical "missing != zero" invariant: no item_stock row
        // should exist for it, not a fabricated system_qty=0 row.
        $dStockCheck = $pdo->prepare('SELECT COUNT(*) FROM item_stock s JOIN items i ON i.id = s.item_id WHERE i.sku = ?');
        $dStockCheck->execute(['LEG-D']);
        T::assertEquals(0, (int) $dStockCheck->fetchColumn(), 'LEG-D (never in stock JSON) has NO item_stock row at all — missing stock is never defaulted to 0');

        $migSRow = $pdo->prepare('SELECT * FROM legacy_migrations WHERE id = ?');
        $migSRow->execute([$commitS['migration_id']]);
        $migSData = $migSRow->fetch();
        T::assertEquals('STOCK', $migSData['type'], 'legacy_migrations row recorded with type=STOCK');
        T::assertEquals(2, (int) $migSData['committed_rows'], 'legacy_migrations.committed_rows for STOCK matches total committed across all locations');
    } finally {
        cleanupLegacyFixtures($pdo);
    }
}

function ensureLegacyUser(PDO $pdo, string $username, string $role): int
{
    $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, \'ACTIVE\')'
    );
    $stmt->execute([$username, password_hash('irrelevant', PASSWORD_DEFAULT), ucfirst($username), $role]);
    return (int) $pdo->lastInsertId();
}

function cleanupLegacyFixtures(PDO $pdo): void
{
    $pdo->exec("DELETE a FROM item_stock_adjustments a JOIN stock_import_batches b ON b.id = a.import_batch_id
                JOIN locations l ON l.id = b.location_id WHERE l.code IN ('LEG-LOC1','LEG-LOC2')");
    $pdo->exec("DELETE s FROM item_stock s JOIN items i ON i.id = s.item_id WHERE i.sku LIKE 'LEG-%'");
    $pdo->exec("DELETE r FROM stock_import_rows r JOIN stock_import_batches b ON b.id = r.batch_id
                JOIN locations l ON l.id = b.location_id WHERE l.code IN ('LEG-LOC1','LEG-LOC2')");
    $pdo->exec("DELETE b FROM stock_import_batches b JOIN locations l ON l.id = b.location_id WHERE l.code IN ('LEG-LOC1','LEG-LOC2')");
    $pdo->exec("DELETE FROM locations WHERE code IN ('LEG-LOC1','LEG-LOC2')");
    $pdo->exec("DELETE FROM legacy_migrations WHERE source_file IN ('legacy_master_test.json','legacy_stock_test.json')");
    $pdo->exec("DELETE FROM audit_logs WHERE action IN ('LEGACY_MIGRATION_MASTER_COMMIT','LEGACY_MIGRATION_STOCK_COMMIT')");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_id IN (SELECT id FROM users WHERE username LIKE 'leg_%')");
    $pdo->exec("DELETE FROM audit_logs WHERE entity_type = 'items' AND entity_id IN (SELECT id FROM items WHERE sku LIKE 'LEG-%')");
    $pdo->exec("DELETE FROM items WHERE sku LIKE 'LEG-%'");
    $pdo->exec("DELETE FROM categories WHERE code = 'LEG-CAT-PRE' OR code LIKE 'UMUM-%'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'leg_%'");
}
