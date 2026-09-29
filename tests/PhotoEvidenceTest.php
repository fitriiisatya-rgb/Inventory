<?php
declare(strict_types=1);

/**
 * PhotoEvidenceService tests against real MariaDB. uploadPhoto()'s full
 * path (including is_uploaded_file()) can only be exercised by a genuine
 * HTTP multipart request, which is what tests/photo_security_test.sh
 * does against a live server — this file tests everything else directly:
 * recompute, supersede, delete, and the view-permission boundary, using
 * photo rows inserted directly to simulate "already uploaded".
 */
function test_photo_evidence(): void
{
    $pdo = Database::pdo();
    cleanupPhotoFixtures($pdo);

    try {
        $pdo->prepare("INSERT INTO categories (code, name, status) VALUES ('PHO-CAT','Photo Test Category','ACTIVE')")->execute();
        $categoryId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO locations (code, name, status) VALUES ('PHO-LOC','Photo Test Location','ACTIVE')")->execute();
        $locationId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO items (sku, name, category_id, buy_unit, buy_content, mid_unit, mid_content, base_unit, last_buy_price, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'ACTIVE\')'
        )->execute(['PHO-KEJU', 'Keju Photo Test', $categoryId, 'Karton', 20000, 'Kg', 20, 'Gr', 16.65]);
        $itemId = (int) $pdo->lastInsertId();

        $superadminId = ensureEngineUser($pdo, 'pho_superadmin', 'SUPERADMIN', null);
        $p1Id = ensureEngineUser($pdo, 'pho_p1', 'COUNTER', 'P1');
        $p2Id = ensureEngineUser($pdo, 'pho_p2', 'COUNTER', 'P2');

        $csv = sys_get_temp_dir() . '/pho_test_import.csv';
        file_put_contents($csv, "sku,system_qty_base,unit_cost\nPHO-KEJU,52200,16.65\n");
        $importService = new StockImportService($pdo);
        $preview = $importService->previewCsv($locationId, $csv, 'pho.csv', $superadminId);
        $importService->commit($preview['batch_id'], $superadminId);

        $locks = new ItemLockService($pdo, 300);
        $uploadDir = sys_get_temp_dir() . '/pho_test_uploads_' . bin2hex(random_bytes(4));
        $GLOBALS['__pho_upload_dir'] = $uploadDir;
        $evidence = new PhotoEvidenceService($pdo, $locks, $uploadDir, 8192);
        $countsService = new CountService($pdo, $locks, $evidence);
        $sessions = new SessionService($pdo);
        $recon = new ReconciliationService($pdo, $locks);

        $session = $sessions->createSession(['name' => 'Photo Test Session', 'location_id' => $locationId, 'scope_type' => 'CATEGORY', 'category_id' => $categoryId], $superadminId);
        $sessions->assignCounter((int) $session['id'], $p1Id, 'P1', $superadminId);
        $sessions->assignCounter((int) $session['id'], $p2Id, 'P2', $superadminId);
        $active = $sessions->startSession((int) $session['id'], $superadminId);

        $siStmt = $pdo->prepare('SELECT * FROM stock_opname_session_items WHERE session_id = ? LIMIT 1');
        $siStmt->execute([$active['id']]);
        $si = $siStmt->fetch();
        $sessionItemId = (int) $si['id'];

        // ------------------------------------------------------------
        T::section('PhotoEvidenceService — recomputeEvidenceStatus()');
        // ------------------------------------------------------------

        $locks->acquire($sessionItemId, 'P1', $p1Id);
        $saved = $countsService->saveCount($sessionItemId, $p1Id, [
            'good_base_input_qty' => 70200,
            'damaged_qty' => 2, 'damaged_unit' => 'Kg',
            'expired_qty' => 1, 'expired_unit' => 'Kg',
            'deadstock_qty' => 0,
        ]);
        $countId = $saved['count_id'];
        T::assertEquals('EVIDENCE_REQUIRED', $saved['evidence_status'], 'Two conditions > 0, no photos yet -> EVIDENCE_REQUIRED');
        T::assertEquals(['DAMAGED', 'EXPIRED'], $saved['evidence_required'], 'Both DAMAGED and EXPIRED flagged as needing evidence');

        insertFakePhoto($pdo, $active['id'], $sessionItemId, $countId, 'DAMAGED', $p1Id);
        $status1 = $evidence->recomputeEvidenceStatus($countId);
        T::assertEquals('EVIDENCE_REQUIRED', $status1, 'Only DAMAGED satisfied — EXPIRED still pending, stays EVIDENCE_REQUIRED');

        insertFakePhoto($pdo, $active['id'], $sessionItemId, $countId, 'EXPIRED', $p1Id);
        $status2 = $evidence->recomputeEvidenceStatus($countId);
        T::assertEquals('COMPLETE', $status2, 'Both conditions now have a photo -> COMPLETE');

        // ------------------------------------------------------------
        T::section('PhotoEvidenceService — deletePhoto() pre/post COMPLETE');
        // ------------------------------------------------------------

        $photosStmt = $pdo->prepare("SELECT * FROM stock_opname_photos WHERE count_id = ? AND condition_type = 'EXPIRED' LIMIT 1");
        $photosStmt->execute([$countId]);
        $expiredPhoto = $photosStmt->fetch();

        $deleteAfterCompleteRejected = false;
        try {
            $evidence->deletePhoto((int) $expiredPhoto['id'], $p1Id);
        } catch (PhotoValidationException $e) {
            $deleteAfterCompleteRejected = true;
        }
        T::assertTrue($deleteAfterCompleteRejected, 'Cannot delete a photo once the count is COMPLETE');

        $wrongUserRejected = false;
        // Force evidence_status back to EVIDENCE_REQUIRED to test the ownership check independent of the COMPLETE gate.
        $pdo->prepare("UPDATE stock_opname_counts SET evidence_status = 'EVIDENCE_REQUIRED' WHERE id = ?")->execute([$countId]);
        try {
            $evidence->deletePhoto((int) $expiredPhoto['id'], $p2Id);
        } catch (PhotoForbiddenException $e) {
            $wrongUserRejected = true;
        }
        T::assertTrue($wrongUserRejected, 'Only the uploader can delete their own photo');

        $deleteResult = $evidence->deletePhoto((int) $expiredPhoto['id'], $p1Id);
        T::assertEquals('EVIDENCE_REQUIRED', $deleteResult['evidence_status'], 'Deleting the only EXPIRED photo re-opens EVIDENCE_REQUIRED');
        $goneStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_photos WHERE id = ?');
        $goneStmt->execute([(int) $expiredPhoto['id']]);
        T::assertEquals(0, (int) $goneStmt->fetchColumn(), 'Pre-COMPLETE delete is a real hard delete, not a status flag');

        insertFakePhoto($pdo, $active['id'], $sessionItemId, $countId, 'EXPIRED', $p1Id);
        $evidence->recomputeEvidenceStatus($countId);

        // ------------------------------------------------------------
        T::section('PhotoEvidenceService — supersede on edit (design review points 22-23)');
        // ------------------------------------------------------------

        $locks->acquire($sessionItemId, 'P1', $p1Id);
        $editedAway = $countsService->saveCount($sessionItemId, $p1Id, [
            'good_base_input_qty' => 70200,
            'damaged_qty' => 0,
            'expired_qty' => 1, 'expired_unit' => 'Kg',
            'deadstock_qty' => 0,
            'reason' => 'Barang rusak ternyata sudah diperbaiki',
        ]);
        $damagedPhotoStmt = $pdo->prepare("SELECT * FROM stock_opname_photos WHERE count_id = ? AND condition_type = 'DAMAGED'");
        $damagedPhotoStmt->execute([$countId]);
        $damagedPhotoAfter = $damagedPhotoStmt->fetch();
        T::assertEquals('SUPERSEDED', $damagedPhotoAfter['status'], 'Editing DAMAGED qty to 0 supersedes its photo — never deletes it');
        T::assertTrue($damagedPhotoAfter['superseded_reason'] !== null, 'Supersede reason recorded');

        $expiredPhotoStmt = $pdo->prepare("SELECT * FROM stock_opname_photos WHERE count_id = ? AND condition_type = 'EXPIRED'");
        $expiredPhotoStmt->execute([$countId]);
        T::assertEquals('ACTIVE', $expiredPhotoStmt->fetch()['status'], 'EXPIRED photo stays ACTIVE — its condition is still > 0');
        T::assertEquals('COMPLETE', $editedAway['evidence_status'], 'DAMAGED no longer needs evidence, EXPIRED still satisfied -> COMPLETE overall');

        // ------------------------------------------------------------
        T::section('PhotoEvidenceService — round isolation (design review point 25)');
        // ------------------------------------------------------------

        $recon->requestRecount($sessionItemId, $superadminId, 'Selisih ditemukan, perlu verifikasi ulang');
        $siStmt->execute([$active['id']]);
        $siAfterRecount = $siStmt->fetch();
        T::assertEquals(2, (int) $siAfterRecount['current_round'], 'Recount bumped to round 2');

        $locks->acquire($sessionItemId, 'P1', $p1Id);
        $round2Save = $countsService->saveCount($sessionItemId, $p1Id, [
            'good_base_input_qty' => 70200,
            'damaged_qty' => 0,
            'expired_qty' => 1, 'expired_unit' => 'Kg',
            'deadstock_qty' => 0,
        ]);
        T::assertEquals('EVIDENCE_REQUIRED', $round2Save['evidence_status'], 'Round 2 has its own EXPIRED qty > 0 and NO photos of its own yet — round 1 photos do not carry over');
        $round2PhotoCheck = $pdo->prepare("SELECT COUNT(*) FROM stock_opname_photos WHERE count_id = ?");
        $round2PhotoCheck->execute([$round2Save['count_id']]);
        T::assertEquals(0, (int) $round2PhotoCheck->fetchColumn(), 'Round 2s count starts with zero photos of its own');
        T::assertTrue($round2Save['count_id'] !== $countId, 'Round 2 is a genuinely different count row from round 1');

        // ------------------------------------------------------------
        T::section('PhotoEvidenceService — getPhotoForViewing() permission boundary');
        // ------------------------------------------------------------

        $anyPhotoStmt = $pdo->prepare("SELECT id FROM stock_opname_photos WHERE count_id = ? LIMIT 1");
        $anyPhotoStmt->execute([$countId]);
        $viewPhotoId = (int) $anyPhotoStmt->fetchColumn();

        $superadminView = $evidence->getPhotoForViewing($viewPhotoId, ['id' => $superadminId, 'role' => 'SUPERADMIN']);
        T::assertTrue(isset($superadminView['absolute_path']), 'SUPERADMIN can view any photo');

        $p1View = $evidence->getPhotoForViewing($viewPhotoId, ['id' => $p1Id, 'role' => 'COUNTER']);
        T::assertTrue(isset($p1View['absolute_path']), 'P1 can view their OWN teams photo');

        $p2Forbidden = false;
        try {
            $evidence->getPhotoForViewing($viewPhotoId, ['id' => $p2Id, 'role' => 'COUNTER']);
        } catch (PhotoForbiddenException $e) {
            $p2Forbidden = true;
        }
        T::assertTrue($p2Forbidden, 'P2 (COUNTER, different team) CANNOT view P1s evidence photo — blind-count applies to evidence too');
    } finally {
        cleanupPhotoFixtures($pdo);
        if (isset($uploadDir) && is_dir($uploadDir)) {
            array_map('unlink', glob("$uploadDir/test/*") ?: []);
            @rmdir("$uploadDir/test");
            @rmdir($uploadDir);
        }
    }
}

/**
 * Simulates "a photo was already uploaded" without needing a real HTTP
 * multipart request — but still backs it with a real file on disk, since
 * getPhotoForViewing() checks file existence after the permission check
 * and this suite wants to reach a genuine success, not just an early exit.
 */
function insertFakePhoto(PDO $pdo, int $sessionId, int $sessionItemId, int $countId, string $conditionType, int $uploaderId): void
{
    $uploadDir = $GLOBALS['__pho_upload_dir'];
    $relativePath = 'uploads/opname/test/fake_' . bin2hex(random_bytes(6)) . '.jpg';
    $absoluteDir = $uploadDir . '/test';
    if (!is_dir($absoluteDir)) {
        mkdir($absoluteDir, 0755, true);
    }
    file_put_contents($uploadDir . '/test/' . basename($relativePath), 'not a real jpeg, just needs to exist for is_file()');

    $pdo->prepare(
        'INSERT INTO stock_opname_photos (session_id, session_item_id, count_id, condition_type, file_path, status, uploaded_by, uploaded_at)
         VALUES (?, ?, ?, ?, ?, \'ACTIVE\', ?, NOW())'
    )->execute([$sessionId, $sessionItemId, $countId, $conditionType, $relativePath, $uploaderId]);
}

function cleanupPhotoFixtures(PDO $pdo): void
{
    $pdo->exec("DELETE p FROM stock_opname_photos p
                JOIN stock_opname_sessions s ON s.id = p.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'PHO-LOC'");
    $pdo->exec("DELETE l FROM stock_opname_item_locks l
                JOIN stock_opname_session_items si ON si.id = l.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'PHO-LOC'");
    $pdo->exec("DELETE cr FROM stock_opname_count_revisions cr
                JOIN stock_opname_counts c ON c.id = cr.count_id
                JOIN stock_opname_session_items si ON si.id = c.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'PHO-LOC'");
    $pdo->exec("DELETE c FROM stock_opname_counts c
                JOIN stock_opname_session_items si ON si.id = c.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'PHO-LOC'");
    $pdo->exec("DELETE rc FROM stock_opname_recounts rc
                JOIN stock_opname_session_items si ON si.id = rc.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'PHO-LOC'");
    $pdo->exec("DELETE si FROM stock_opname_session_items si
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'PHO-LOC'");
    $pdo->exec("DELETE sc FROM stock_opname_session_counters sc
                JOIN stock_opname_sessions s ON s.id = sc.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'PHO-LOC'");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_id IN (SELECT id FROM users WHERE username LIKE 'pho_%')");
    $pdo->exec("DELETE s FROM stock_opname_sessions s JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'PHO-LOC'");
    $pdo->exec("DELETE a FROM item_stock_adjustments a JOIN item_stock st ON st.id = a.item_stock_id JOIN items i ON i.id = st.item_id WHERE i.sku LIKE 'PHO-%'");
    $pdo->exec("DELETE st FROM item_stock st JOIN items i ON i.id = st.item_id WHERE i.sku LIKE 'PHO-%'");
    $pdo->exec("DELETE r FROM stock_import_rows r JOIN stock_import_batches b ON b.id = r.batch_id JOIN locations l ON l.id = b.location_id WHERE l.code = 'PHO-LOC'");
    $pdo->exec("DELETE b FROM stock_import_batches b JOIN locations l ON l.id = b.location_id WHERE l.code = 'PHO-LOC'");
    $pdo->exec("DELETE FROM items WHERE sku LIKE 'PHO-%'");
    $pdo->exec("DELETE FROM categories WHERE code = 'PHO-CAT'");
    $pdo->exec("DELETE FROM locations WHERE code = 'PHO-LOC'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'pho_%'");
}
