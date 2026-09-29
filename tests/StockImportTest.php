<?php
declare(strict_types=1);

function test_stock_import(): void
{
    T::section('StockImportService — preview/commit against real MariaDB');

    $pdo = Database::pdo();
    cleanupStockImportFixtures($pdo);

    try {
        $pdo->prepare("INSERT INTO categories (code, name, status) VALUES ('TEST-CAT','Test Category','ACTIVE')")->execute();
        $categoryId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO locations (code, name, status) VALUES ('TEST-LOC','Test Location','ACTIVE')")->execute();
        $locationId = (int) $pdo->lastInsertId();

        $itemIds = [];
        $items = [
            ['TEST-KEJU',   'Keju Test',   'Karton', 20000, 'Kg', 20, 'Gr',  100],
            ['TEST-MINYAK', 'Minyak Test', 'Karton', 12,    null, null, 'Pcs', 5000],
            ['TEST-TELUR',  'Telur Test',  'Pcs',    1,     null, null, 'Pcs', 2000],
            ['TEST-GULA',   'Gula Test',   'Sak',    25000, 'Kg', 25, 'Gr',  15],
            ['TEST-GARAM',  'Garam Test',  'Sak',    25000, 'Kg', 25, 'Gr',  10],
        ];
        $ins = $pdo->prepare(
            'INSERT INTO items (sku, name, category_id, buy_unit, buy_content, mid_unit, mid_content, base_unit, last_buy_price, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'ACTIVE\')'
        );
        foreach ($items as [$sku, $name, $buyUnit, $buyContent, $midUnit, $midContent, $baseUnit, $price]) {
            $ins->execute([$sku, $name, $categoryId, $buyUnit, $buyContent, $midUnit, $midContent, $baseUnit, $price]);
            $itemIds[$sku] = (int) $pdo->lastInsertId();
        }

        // Pre-seed TEST-GARAM with a small existing system_qty to trigger the
        // "big jump" WARNING sanity check on the second import batch.
        $pdo->prepare('INSERT INTO item_stock (item_id, location_id, system_qty, unit_cost) VALUES (?, ?, 100, 10)')
            ->execute([$itemIds['TEST-GARAM'], $locationId]);

        // --- Batch 1: qty+unit format, exercising every non-warning status ---
        $csv1 = sys_get_temp_dir() . '/so_test_import_1.csv';
        file_put_contents($csv1, implode("\n", [
            'sku,qty,unit,unit_cost',
            'TEST-KEJU,2,Karton,12',      // MATCHED -> 2*20000 = 40000 Gr
            'TEST-MINYAK,9,Karton,5000',  // MATCHED -> 9*12 = 108 Pcs
            'TEST-MINYAK,3,Karton,5000',  // DUPLICATE (sku repeats)
            'TEST-NOPE,5,Pcs,100',        // SKU_NOT_FOUND
            'TEST-TELUR,abc,Pcs,10',      // INVALID_QTY
            'TEST-GULA,10,Liter,20',      // INVALID_UNIT (Liter is not Sak/Kg/Gr)
        ]));

        $service = new StockImportService($pdo);
        $uploaderId = ensureTestUploader($pdo);

        $result1 = $service->previewCsv($locationId, $csv1, 'batch1.csv', $uploaderId);
        $batch1 = $result1['batch_id'];
        $s1 = $result1['summary'];

        T::assertEquals(6, $s1['total'], 'Batch 1: total rows = 6');
        T::assertEquals(2, $s1['matched'], 'Batch 1: 2 MATCHED rows');
        T::assertEquals(1, $s1['duplicate'], 'Batch 1: 1 DUPLICATE row');
        T::assertEquals(1, $s1['sku_not_found'], 'Batch 1: 1 SKU_NOT_FOUND row');
        T::assertEquals(1, $s1['invalid_qty'], 'Batch 1: 1 INVALID_QTY row');
        T::assertEquals(1, $s1['invalid_unit'], 'Batch 1: 1 INVALID_UNIT row');

        $rows1 = $pdo->prepare('SELECT * FROM stock_import_rows WHERE batch_id = ? ORDER BY row_no');
        $rows1->execute([$batch1]);
        $rowsBySkuLine = [];
        foreach ($rows1->fetchAll() as $r) {
            $rowsBySkuLine[$r['row_no']] = $r;
        }
        T::assertEquals(40000.0, (float) $rowsBySkuLine[2]['parsed_qty_base'], 'Keju row normalized to 40.000 Gr (2 Karton)');
        T::assertEquals(108.0, (float) $rowsBySkuLine[3]['parsed_qty_base'], 'Minyak row normalized to 108 Pcs (9 Karton)');
        T::assertEquals('DUPLICATE', $rowsBySkuLine[4]['status'], 'Second TEST-MINYAK row flagged DUPLICATE');
        T::assertEquals('SKU_NOT_FOUND', $rowsBySkuLine[5]['status'], 'Unknown SKU flagged SKU_NOT_FOUND');
        T::assertEquals('INVALID_QTY', $rowsBySkuLine[6]['status'], 'Non-numeric qty flagged INVALID_QTY');
        T::assertEquals('INVALID_UNIT', $rowsBySkuLine[7]['status'], 'Unrecognized unit flagged INVALID_UNIT');

        $commit1 = $service->commit($batch1, $uploaderId);
        T::assertEquals(2, $commit1['committed'], 'Only the 2 MATCHED rows get committed');

        $stockStmt = $pdo->prepare('SELECT system_qty, unit_cost FROM item_stock WHERE item_id = ? AND location_id = ?');
        $stockStmt->execute([$itemIds['TEST-KEJU'], $locationId]);
        $kejuStock = $stockStmt->fetch();
        T::assertEquals(40000.0, (float) $kejuStock['system_qty'], 'item_stock.system_qty for Keju = 40.000 after commit');
        T::assertEquals(12.0, (float) $kejuStock['unit_cost'], 'item_stock.unit_cost for Keju = 12 (from CSV)');

        $stockStmt->execute([$itemIds['TEST-MINYAK'], $locationId]);
        $minyakStock = $stockStmt->fetch();
        T::assertEquals(108.0, (float) $minyakStock['system_qty'], 'item_stock.system_qty for Minyak = 108 after commit');

        $adjStmt = $pdo->prepare(
            'SELECT a.* FROM item_stock_adjustments a JOIN item_stock s ON s.id = a.item_stock_id WHERE s.item_id = ?'
        );
        $adjStmt->execute([$itemIds['TEST-KEJU']]);
        $adj = $adjStmt->fetch();
        T::assertTrue($adj !== false, 'item_stock_adjustments row created for Keju import');
        T::assertEquals(0.0, (float) $adj['old_qty'], 'Adjustment old_qty = 0 (no prior stock)');
        T::assertEquals(40000.0, (float) $adj['new_qty'], 'Adjustment new_qty = 40.000');
        T::assertEquals('IMPORT', $adj['source'], "Adjustment source = 'IMPORT'");

        $batchStatus = $pdo->prepare('SELECT status FROM stock_import_batches WHERE id = ?');
        $batchStatus->execute([$batch1]);
        T::assertEquals('COMMITTED', $batchStatus->fetchColumn(), 'Batch 1 status = COMMITTED after commit');

        $threw = false;
        try {
            $service->commit($batch1, $uploaderId);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        T::assertTrue($threw, 'Committing an already-COMMITTED batch throws');

        // --- Batch 2: system_qty_base format, exercising the WARNING sanity check ---
        $csv2 = sys_get_temp_dir() . '/so_test_import_2.csv';
        file_put_contents($csv2, implode("\n", [
            'sku,system_qty_base,unit_cost',
            'TEST-GARAM,50000,15', // old=100, new=50000 -> jump > old*10 -> WARNING
        ]));

        $result2 = $service->previewCsv($locationId, $csv2, 'batch2.csv', $uploaderId);
        $batch2 = $result2['batch_id'];
        T::assertEquals(1, $result2['summary']['warning'], 'Batch 2: big qty jump flagged as WARNING, not silently MATCHED');
        T::assertEquals(0, $result2['summary']['matched'], 'Batch 2: the WARNING row is not double-counted as MATCHED');

        $commit2 = $service->commit($batch2, $uploaderId);
        T::assertEquals(1, $commit2['committed'], 'WARNING rows are still committed (not blocking, just flagged)');

        $stockStmt->execute([$itemIds['TEST-GARAM'], $locationId]);
        $garamStock = $stockStmt->fetch();
        T::assertEquals(50000.0, (float) $garamStock['system_qty'], 'Garam system_qty updated to 50.000 despite WARNING');

        T::section('SystemStockProvider — ImportSystemStockProvider');
        $provider = new ImportSystemStockProvider($pdo);
        $kejuResult = $provider->getSystemStock($itemIds['TEST-KEJU'], $locationId);
        T::assertTrue($kejuResult !== null, 'Provider returns a result for an imported item');
        T::assertEquals(40000.0, $kejuResult->qty, 'Provider returns correct qty for Keju');
        T::assertEquals('IMPORT', $kejuResult->source, "Provider source = 'IMPORT'");

        $neverImported = $provider->getSystemStock($itemIds['TEST-TELUR'], $locationId);
        T::assertTrue($neverImported === null, 'Provider returns null for an item never imported at this location');

        Audit::log(null, '__TEST_MARKER__', 'test', 0);
        $auditCheck = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'STOCK_IMPORT_COMMIT'");
        $auditCheck->execute();
        T::assertTrue((int) $auditCheck->fetchColumn() >= 2, 'Audit log recorded STOCK_IMPORT_COMMIT for both commits');
    } finally {
        cleanupStockImportFixtures($pdo);
    }
}

function ensureTestUploader(PDO $pdo): int
{
    $pdo->prepare("DELETE FROM users WHERE username = 'test_uploader'")->execute();
    $pdo->prepare(
        "INSERT INTO users (username, password_hash, full_name, role, status) VALUES ('test_uploader', ?, 'Test Uploader', 'SUPERADMIN', 'ACTIVE')"
    )->execute([password_hash('irrelevant', PASSWORD_DEFAULT)]);
    return (int) $pdo->lastInsertId();
}

function cleanupStockImportFixtures(PDO $pdo): void
{
    $pdo->exec("DELETE a FROM item_stock_adjustments a
                JOIN item_stock s ON s.id = a.item_stock_id
                JOIN items i ON i.id = s.item_id
                WHERE i.sku LIKE 'TEST-%'");
    $pdo->exec("DELETE s FROM item_stock s JOIN items i ON i.id = s.item_id WHERE i.sku LIKE 'TEST-%'");
    $pdo->exec("DELETE r FROM stock_import_rows r
                JOIN stock_import_batches b ON b.id = r.batch_id
                JOIN locations l ON l.id = b.location_id
                WHERE l.code = 'TEST-LOC'");
    $pdo->exec("DELETE b FROM stock_import_batches b JOIN locations l ON l.id = b.location_id WHERE l.code = 'TEST-LOC'");
    $pdo->exec("DELETE FROM audit_logs WHERE entity_type = 'test' OR action = '__TEST_MARKER__'");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_id IN (SELECT id FROM users WHERE username = 'test_uploader')");
    $pdo->exec("DELETE FROM items WHERE sku LIKE 'TEST-%'");
    $pdo->exec("DELETE FROM categories WHERE code = 'TEST-CAT'");
    $pdo->exec("DELETE FROM locations WHERE code = 'TEST-LOC'");
    $pdo->exec("DELETE FROM users WHERE username = 'test_uploader'");
}
