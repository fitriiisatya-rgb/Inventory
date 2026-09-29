<?php
declare(strict_types=1);

/**
 * Phase 4 SO engine tests: SessionService, ItemLockService, CountService,
 * ReconciliationService — exercised directly against the real MariaDB
 * instance (no mocks). HTTP-layer RBAC/IDOR and true concurrency are
 * covered separately by tests/api_security_test.sh and
 * tests/concurrency_test.sh, which need a running dev server.
 */
function test_engine(): void
{
    $pdo = Database::pdo();
    cleanupEngineFixtures($pdo);

    try {
        // ------------------------------------------------------------
        // Fixtures
        // ------------------------------------------------------------
        $pdo->prepare("INSERT INTO categories (code, name, status) VALUES ('ENG-CAT','Engine Test Category','ACTIVE')")->execute();
        $categoryId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO locations (code, name, status) VALUES ('ENG-LOC','Engine Test Location','ACTIVE')")->execute();
        $locationId = (int) $pdo->lastInsertId();

        $itemIds = [];
        $itemSpecs = [
            ['ENG-KEJU', 'Keju Engine', 'Karton', 20000, 'Kg', 20, 'Gr', 16.65],
            ['ENG-MINYAK', 'Minyak Engine', 'Karton', 12, null, null, 'Pcs', 5000],
        ];
        $insItem = $pdo->prepare(
            'INSERT INTO items (sku, name, category_id, buy_unit, buy_content, mid_unit, mid_content, base_unit, last_buy_price, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'ACTIVE\')'
        );
        foreach ($itemSpecs as [$sku, $name, $buyUnit, $buyContent, $midUnit, $midContent, $baseUnit, $price]) {
            $insItem->execute([$sku, $name, $categoryId, $buyUnit, $buyContent, $midUnit, $midContent, $baseUnit, $price]);
            $itemIds[$sku] = (int) $pdo->lastInsertId();
        }

        $superadminId = ensureEngineUser($pdo, 'eng_superadmin', 'SUPERADMIN', null);
        $p1aId = ensureEngineUser($pdo, 'eng_p1a', 'COUNTER', 'P1');
        $p1bId = ensureEngineUser($pdo, 'eng_p1b', 'COUNTER', 'P1');
        $p2aId = ensureEngineUser($pdo, 'eng_p2a', 'COUNTER', 'P2');

        // Commit a stock import batch so SessionService can auto-bind
        // system_stock_batch_id through the real pipeline, not a shortcut.
        $csv = sys_get_temp_dir() . '/eng_test_import.csv';
        file_put_contents($csv, "sku,system_qty_base,unit_cost\nENG-KEJU,52200,16.65\nENG-MINYAK,120,5000\n");
        $importService = new StockImportService($pdo);
        $preview = $importService->previewCsv($locationId, $csv, 'eng.csv', $superadminId);
        $importService->commit($preview['batch_id'], $superadminId);

        $locks = new ItemLockService($pdo, 300);
        $sessions = new SessionService($pdo);
        $counts = new CountService($pdo, $locks);
        $recon = new ReconciliationService($pdo, $locks);

        // ------------------------------------------------------------
        T::section('SessionService — create, traceability, preflight, atomic snapshot');
        // ------------------------------------------------------------

        $session = $sessions->createSession(['name' => 'Engine Test Session', 'location_id' => $locationId, 'scope_type' => 'CATEGORY', 'category_id' => $categoryId], $superadminId);
        T::assertEquals('DRAFT', $session['status'], 'New session starts DRAFT');
        T::assertEquals($preview['batch_id'], (int) $session['system_stock_batch_id'], 'system_stock_batch_id auto-bound to the latest COMMITTED batch for this location');

        $pre = $sessions->preflight((int) $session['id']);
        T::assertTrue(in_array('Belum ada petugas P1 yang di-assign.', $pre['blockers'], true), 'Preflight blocks on missing P1');
        T::assertTrue(in_array('Belum ada petugas P2 yang di-assign.', $pre['blockers'], true), 'Preflight blocks on missing P2');
        T::assertEquals(2, $pre['item_count'], 'Preflight resolves 2 SKUs in category scope');

        $sessions->assignCounter((int) $session['id'], $p1aId, 'P1', $superadminId);
        $sessions->assignCounter((int) $session['id'], $p1bId, 'P1', $superadminId);
        $sessions->assignCounter((int) $session['id'], $p2aId, 'P2', $superadminId);

        $pre2 = $sessions->preflight((int) $session['id']);
        T::assertEquals([], $pre2['blockers'], 'Preflight clean after P1+P2 assigned');

        $active = $sessions->startSession((int) $session['id'], $superadminId);
        T::assertEquals('ACTIVE', $active['status'], 'Session becomes ACTIVE after start');
        T::assertTrue($active['snapshot_at'] !== null, 'snapshot_at recorded');

        try {
            $sessions->assignCounter((int) $session['id'], $p2aId, 'P1', $superadminId);
            $threw = false;
        } catch (RuntimeException $e) {
            $threw = true;
        }
        T::assertTrue($threw, 'Assignment is rejected once session is no longer DRAFT');

        $threwDouble = false;
        try {
            $sessions->startSession((int) $session['id'], $superadminId);
        } catch (RuntimeException $e) {
            $threwDouble = true;
        }
        T::assertTrue($threwDouble, 'Starting an already-ACTIVE session is rejected (no double-snapshot)');

        $siStmt = $pdo->prepare('SELECT * FROM stock_opname_session_items WHERE session_id = ? ORDER BY sku_snapshot');
        $siStmt->execute([$session['id']]);
        $sessionItems = $siStmt->fetchAll();
        T::assertEquals(2, count($sessionItems), 'Snapshot created exactly 2 session_items');
        $kejuSi = $sessionItems[array_search('ENG-KEJU', array_column($sessionItems, 'sku_snapshot'), true)];
        T::assertEquals(52200.0, (float) $kejuSi['system_qty_snapshot'], 'system_qty_snapshot pulled from the committed import batch (via SystemStockProvider)');
        T::assertEquals(16.65, (float) $kejuSi['unit_cost_snapshot'], 'unit_cost_snapshot pulled from the committed import batch');
        T::assertEquals(1, (int) $kejuSi['current_round'], 'current_round starts at 1');

        // Immutability: change Master Barang after ACTIVE, confirm the snapshot doesn't move.
        $pdo->prepare("UPDATE items SET name = 'RENAMED AFTER SNAPSHOT', buy_content = 99999 WHERE id = ?")->execute([$itemIds['ENG-KEJU']]);
        $siStmt->execute([$session['id']]);
        $sessionItemsAfter = $siStmt->fetchAll();
        $kejuSiAfter = $sessionItemsAfter[array_search('ENG-KEJU', array_column($sessionItemsAfter, 'sku_snapshot'), true)];
        T::assertEquals('Keju Engine', $kejuSiAfter['name_snapshot'], 'name_snapshot unaffected by later Master Barang edit');
        T::assertEquals(20000.0, (float) $kejuSiAfter['buy_content_snapshot'], 'buy_content_snapshot unaffected by later Master Barang edit');

        $kejuSessionItemId = (int) $kejuSiAfter['id'];
        $minyakSiAfter = $sessionItemsAfter[array_search('ENG-MINYAK', array_column($sessionItemsAfter, 'sku_snapshot'), true)];
        $minyakSessionItemId = (int) $minyakSiAfter['id'];

        // ------------------------------------------------------------
        T::section('ItemLockService — per-team locking');
        // ------------------------------------------------------------

        $r1 = $locks->acquire($kejuSessionItemId, 'P1', $p1aId);
        T::assertTrue($r1['ok'], 'P1 Andi-equivalent acquires lock');
        $r2 = $locks->acquire($kejuSessionItemId, 'P1', $p1bId);
        T::assertFalse($r2['ok'], 'Second P1 user cannot acquire the same slot while held');
        $r3 = $locks->acquire($kejuSessionItemId, 'P2', $p2aId);
        T::assertTrue($r3['ok'], 'P2 acquires the SAME item concurrently with P1 — different team, independent lock');

        $hb = $locks->heartbeat($kejuSessionItemId, 'P1', $p1aId);
        T::assertTrue($hb, 'Heartbeat succeeds for the lock owner');
        $hbWrong = $locks->heartbeat($kejuSessionItemId, 'P1', $p1bId);
        T::assertFalse($hbWrong, 'Heartbeat fails for a non-owner');

        // Simulate an expired lock and confirm takeover succeeds.
        $pdo->prepare('UPDATE stock_opname_item_locks SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE session_item_id = ? AND team = ?')
            ->execute([$kejuSessionItemId, 'P1']);
        $r4 = $locks->acquire($kejuSessionItemId, 'P1', $p1bId);
        T::assertTrue($r4['ok'], 'Expired lock is reaped and a new user can acquire it');

        $locks->release($kejuSessionItemId, 'P1', $p1bId);
        $locks->release($kejuSessionItemId, 'P2', $p2aId);

        // ------------------------------------------------------------
        T::section('CountService — save, validation, zero-count, edit+reason+revision');
        // ------------------------------------------------------------

        $locks->acquire($kejuSessionItemId, 'P1', $p1aId);
        $saved = $counts->saveCount($kejuSessionItemId, $p1aId, [
            'good_buy_qty' => 2, 'good_mid_qty' => 30, 'good_base_input_qty' => 200,
            'damaged_qty' => 1, 'damaged_unit' => 'Kg',
            'expired_qty' => 0, 'deadstock_qty' => 0,
        ]);
        T::assertEquals(70200.0, $saved['good_base_qty'], 'Good stock normalized: 2 Karton + 30 Kg + 200 Gr = 70.200 Gr');
        T::assertEquals(71200.0, $saved['physical_base_qty'], 'physical = good + damaged (1 Kg = 1.000 Gr) = 71.200 Gr');
        T::assertEquals(70200.0, $saved['available_base_qty'], 'available = good only, not including damaged');
        T::assertEquals(['DAMAGED'], $saved['evidence_required'], 'evidence_required hook flags DAMAGED (qty > 0), Phase 5 owns enforcement');

        $countRow = $pdo->prepare('SELECT * FROM stock_opname_counts WHERE id = ?');
        $countRow->execute([$saved['count_id']]);
        $countRowData = $countRow->fetch();
        T::assertEquals('P1', $countRowData['team'], 'Count recorded under the correct team');

        $revCountStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_count_revisions WHERE count_id = ?');
        $revCountStmt->execute([$saved['count_id']]);
        T::assertEquals(1, (int) $revCountStmt->fetchColumn(), 'First save writes exactly one revision (old_value NULL)');

        // Lock was released by the save itself — saving again without reacquiring must fail.
        $lockGoneException = null;
        try {
            $counts->saveCount($kejuSessionItemId, $p1aId, ['good_base_input_qty' => 999, 'damaged_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 0]);
        } catch (CountLockException $e) {
            $lockGoneException = $e;
        }
        T::assertTrue($lockGoneException !== null, 'Lock is released after a successful save; a second save requires re-acquiring it');

        // Re-acquire and edit WITHOUT reason -> rejected.
        $locks->acquire($kejuSessionItemId, 'P1', $p1aId);
        $noReasonRejected = false;
        try {
            $counts->saveCount($kejuSessionItemId, $p1aId, ['good_base_input_qty' => 70200, 'good_buy_qty' => 0, 'good_mid_qty' => 0, 'damaged_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 0]);
        } catch (CountValidationException $e) {
            $noReasonRejected = true;
        }
        T::assertTrue($noReasonRejected, 'Editing an existing count without a reason is rejected');

        // Edit WITH reason -> accepted, revision captures old/new JSON.
        $locks->acquire($kejuSessionItemId, 'P1', $p1aId); // rejected save didn't consume the lock's validity
        $edited = $counts->saveCount($kejuSessionItemId, $p1aId, [
            'good_buy_qty' => 0, 'good_mid_qty' => 0, 'good_base_input_qty' => 70200,
            'damaged_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 0,
            'reason' => 'Koreksi setelah verifikasi ulang',
        ]);
        T::assertEquals(70200.0, $edited['good_base_qty'], 'Edited good_base_qty reflects new input');
        T::assertEquals($saved['count_id'], $edited['count_id'], 'Edit updates the SAME official row, not a second one');

        $revCountStmt->execute([$saved['count_id']]);
        T::assertEquals(2, (int) $revCountStmt->fetchColumn(), 'Edit adds a second revision row');
        $revStmt = $pdo->prepare('SELECT * FROM stock_opname_count_revisions WHERE count_id = ? ORDER BY id DESC LIMIT 1');
        $revStmt->execute([$saved['count_id']]);
        $lastRev = $revStmt->fetch();
        T::assertEquals('Koreksi setelah verifikasi ulang', $lastRev['reason'], 'Revision stores the reason');
        $oldVal = json_decode($lastRev['old_value'], true);
        $newVal = json_decode($lastRev['new_value'], true);
        T::assertEquals(1.0, $oldVal['damaged_qty'], 'Revision old_value is a full JSON snapshot of the pre-edit state');
        T::assertEquals(0.0, $newVal['damaged_qty'], 'Revision new_value is a full JSON snapshot of the post-edit state');

        // Zero count on Minyak — must be SUDAH_DIHITUNG, not BELUM.
        $locks->acquire($minyakSessionItemId, 'P1', $p1aId);
        $zeroSave = $counts->saveCount($minyakSessionItemId, $p1aId, ['good_buy_qty' => 0, 'good_base_input_qty' => 0, 'damaged_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 0]);
        T::assertEquals(0.0, $zeroSave['physical_base_qty'], 'Zero count saved with physical = 0');
        $minyakSiRow = $pdo->prepare('SELECT * FROM stock_opname_session_items WHERE id = ?');
        $minyakSiRow->execute([$minyakSessionItemId]);
        $minyakSiData = $minyakSiRow->fetch();
        $zeroCountRow = $pdo->prepare("SELECT * FROM stock_opname_counts WHERE session_item_id = ? AND team = 'P1' AND round = 1");
        $zeroCountRow->execute([$minyakSessionItemId]);
        $status = $recon->counterStatus($minyakSiData, 'P1', $zeroCountRow->fetch());
        T::assertEquals('SUDAH_DIHITUNG', $status, 'Zero count -> SUDAH_DIHITUNG, never BELUM_DIHITUNG (record existence, not qty, decides this)');

        // Item NOT NORMAL blocks saving.
        $pdo->prepare("UPDATE stock_opname_session_items SET item_status = 'NOT_COUNTABLE' WHERE id = ?")->execute([$minyakSessionItemId]);
        $blockedByStatus = false;
        try {
            $counts->saveCount($minyakSessionItemId, $p2aId, ['good_base_input_qty' => 1, 'damaged_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 0]);
        } catch (CountValidationException $e) {
            $blockedByStatus = true;
        }
        T::assertTrue($blockedByStatus, 'Cannot save a count on a NOT_COUNTABLE item');
        $pdo->prepare("UPDATE stock_opname_session_items SET item_status = 'NORMAL' WHERE id = ?")->execute([$minyakSessionItemId]);

        // ------------------------------------------------------------
        T::section('ReconciliationService — MATCH / MISMATCH / CONDITION_MISMATCH / RECOUNT_REQUIRED / NOT_COUNTABLE');
        // ------------------------------------------------------------

        $normalSi = ['item_status' => 'NORMAL', 'current_round' => 1];
        $p1 = ['good_base_qty' => 100, 'damaged_base_qty' => 0, 'expired_base_qty' => 0, 'deadstock_base_qty' => 0, 'physical_base_qty' => 100];
        $p2same = ['good_base_qty' => 100, 'damaged_base_qty' => 0, 'expired_base_qty' => 0, 'deadstock_base_qty' => 0, 'physical_base_qty' => 100];
        T::assertEquals('MATCH', $recon->reviewStatus($normalSi, $p1, $p2same), 'Identical composition -> MATCH');

        $p2sameTotalDiffComposition = ['good_base_qty' => 95, 'damaged_base_qty' => 5, 'expired_base_qty' => 0, 'deadstock_base_qty' => 0, 'physical_base_qty' => 100];
        T::assertEquals('CONDITION_MISMATCH', $recon->reviewStatus($normalSi, $p1, $p2sameTotalDiffComposition), 'Same total, different composition -> CONDITION_MISMATCH, not MATCH');

        $p2diffTotal = ['good_base_qty' => 90, 'damaged_base_qty' => 0, 'expired_base_qty' => 0, 'deadstock_base_qty' => 0, 'physical_base_qty' => 90];
        T::assertEquals('MISMATCH', $recon->reviewStatus($normalSi, $p1, $p2diffTotal), 'Different total -> MISMATCH');

        T::assertEquals('BELUM_DIHITUNG', $recon->reviewStatus($normalSi, null, null), 'No counts yet, round 1 -> BELUM_DIHITUNG');
        T::assertEquals('PARTIAL', $recon->reviewStatus($normalSi, $p1, null), 'Only P1 done, round 1 -> PARTIAL');

        $round2Si = ['item_status' => 'NORMAL', 'current_round' => 2];
        T::assertEquals('RECOUNT_REQUIRED', $recon->reviewStatus($round2Si, null, null), 'Round > 1, nothing counted yet -> RECOUNT_REQUIRED (superadmin vocabulary)');

        $notCountableSi = ['item_status' => 'NOT_COUNTABLE', 'current_round' => 1];
        T::assertEquals('NOT_COUNTABLE', $recon->reviewStatus($notCountableSi, $p1, $p2same), 'NOT_COUNTABLE overrides any count comparison');

        // requestRecount(): round bump, old round preserved, locks cleared.
        $beforeRecountRound = (int) $kejuSiAfter['current_round'];
        $recon->requestRecount($kejuSessionItemId, $superadminId, 'Selisih tidak wajar, perlu verifikasi ulang');
        $kejuAfterRecount = $pdo->prepare('SELECT * FROM stock_opname_session_items WHERE id = ?');
        $kejuAfterRecount->execute([$kejuSessionItemId]);
        $kejuAfterRecountData = $kejuAfterRecount->fetch();
        T::assertEquals($beforeRecountRound + 1, (int) $kejuAfterRecountData['current_round'], 'requestRecount() bumps current_round');

        $oldRoundCount = $pdo->prepare('SELECT * FROM stock_opname_counts WHERE session_item_id = ? AND round = 1');
        $oldRoundCount->execute([$kejuSessionItemId]);
        T::assertTrue($oldRoundCount->fetch() !== false, 'Round 1 count row is still present and untouched after recount');

        $recountRow = $pdo->prepare('SELECT * FROM stock_opname_recounts WHERE session_item_id = ? ORDER BY id DESC LIMIT 1');
        $recountRow->execute([$kejuSessionItemId]);
        $recountData = $recountRow->fetch();
        T::assertEquals('Selisih tidak wajar, perlu verifikasi ulang', $recountData['reason'], 'Recount reason is stored');
        T::assertEquals($superadminId, (int) $recountData['requested_by'], 'Recount requester recorded');

        // recount with no prior count at all on the current round -> rejected.
        // kejuSessionItemId was just bumped to round 2 above with nothing
        // counted yet on that round, so this exercises the guard directly.
        $guardTriggered = false;
        try {
            $recon->requestRecount($kejuSessionItemId, $superadminId, 'no counts yet on round 2');
        } catch (RuntimeException $e) {
            $guardTriggered = true;
        }
        T::assertTrue($guardTriggered, 'requestRecount() refuses when the current round has no counts at all yet');

        // NOT_COUNTABLE: reason required, variance stays NULL (no finals row), audit logged.
        // Uses kejuSessionItemId, which is genuinely never-counted on its
        // current round (2) at this point — the case the rule is actually
        // about. (minyakSessionItemId already has a real round-1 P1 count
        // from the CountService section above, so a variance there would be
        // honest historical data, not a "fake zero" — the wrong item to
        // prove this rule with.)
        $emptyReasonRejected = false;
        try {
            $recon->setNotCountable($kejuSessionItemId, $superadminId, '');
        } catch (InvalidArgumentException $e) {
            $emptyReasonRejected = true;
        }
        T::assertTrue($emptyReasonRejected, 'setNotCountable() requires a non-empty reason');

        $recon->setNotCountable($kejuSessionItemId, $superadminId, 'Barang tidak ditemukan secara fisik');
        $reviewRows = $recon->listForReview((int) $session['id']);
        $kejuReviewRow = null;
        foreach ($reviewRows as $r) {
            if ($r['session_item']['id'] == $kejuSessionItemId) {
                $kejuReviewRow = $r;
            }
        }
        T::assertEquals('NOT_COUNTABLE', $kejuReviewRow['status'], 'listForReview reflects NOT_COUNTABLE status');
        T::assertTrue($kejuReviewRow['p1'] === null && $kejuReviewRow['variance_p1'] === null, 'Never-counted round has no count row, so variance is NULL, not a fabricated -system_qty');

        $finalsCheck = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_finals WHERE session_item_id = ?');
        $finalsCheck->execute([$kejuSessionItemId]);
        T::assertEquals(0, (int) $finalsCheck->fetchColumn(), 'NOT_COUNTABLE never creates a fabricated stock_opname_finals row');

        $auditCheck = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'ITEM_NOT_COUNTABLE' AND entity_id = ?");
        $auditCheck->execute([$kejuSessionItemId]);
        T::assertTrue((int) $auditCheck->fetchColumn() >= 1, 'ITEM_NOT_COUNTABLE is audit logged');

        $recon->clearNotCountable($kejuSessionItemId, $superadminId);
        $clearedRow = $pdo->prepare('SELECT item_status FROM stock_opname_session_items WHERE id = ?');
        $clearedRow->execute([$kejuSessionItemId]);
        T::assertEquals('NORMAL', $clearedRow->fetchColumn(), 'clearNotCountable() reverts item_status to NORMAL');
    } finally {
        cleanupEngineFixtures($pdo);
    }
}

function ensureEngineUser(PDO $pdo, string $username, string $role, ?string $team): int
{
    $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, role, team, status) VALUES (?, ?, ?, ?, ?, \'ACTIVE\')'
    );
    $stmt->execute([$username, password_hash('irrelevant', PASSWORD_DEFAULT), ucfirst($username), $role, $team]);
    return (int) $pdo->lastInsertId();
}

function cleanupEngineFixtures(PDO $pdo): void
{
    $pdo->exec("DELETE l FROM stock_opname_item_locks l
                JOIN stock_opname_session_items si ON si.id = l.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'ENG-LOC'");
    $pdo->exec("DELETE cr FROM stock_opname_count_revisions cr
                JOIN stock_opname_counts c ON c.id = cr.count_id
                JOIN stock_opname_session_items si ON si.id = c.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'ENG-LOC'");
    $pdo->exec("DELETE c FROM stock_opname_counts c
                JOIN stock_opname_session_items si ON si.id = c.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'ENG-LOC'");
    $pdo->exec("DELETE rc FROM stock_opname_recounts rc
                JOIN stock_opname_session_items si ON si.id = rc.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'ENG-LOC'");
    $pdo->exec("DELETE si FROM stock_opname_session_items si
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'ENG-LOC'");
    $pdo->exec("DELETE sc FROM stock_opname_session_counters sc
                JOIN stock_opname_sessions s ON s.id = sc.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'ENG-LOC'");
    $pdo->exec("DELETE al FROM audit_logs al WHERE al.entity_type = 'stock_opname_sessions'
                AND al.entity_id IN (SELECT s.id FROM stock_opname_sessions s JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'ENG-LOC')");
    $pdo->exec("DELETE al FROM audit_logs al WHERE al.entity_type = 'stock_opname_session_items'
                AND al.entity_id IN (SELECT si.id FROM stock_opname_session_items si
                    JOIN stock_opname_sessions s ON s.id = si.session_id
                    JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'ENG-LOC')");
    $pdo->exec("DELETE s FROM stock_opname_sessions s JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'ENG-LOC'");
    $pdo->exec("DELETE a FROM item_stock_adjustments a JOIN item_stock s ON s.id = a.item_stock_id JOIN items i ON i.id = s.item_id WHERE i.sku LIKE 'ENG-%'");
    $pdo->exec("DELETE s FROM item_stock s JOIN items i ON i.id = s.item_id WHERE i.sku LIKE 'ENG-%'");
    $pdo->exec("DELETE r FROM stock_import_rows r JOIN stock_import_batches b ON b.id = r.batch_id JOIN locations l ON l.id = b.location_id WHERE l.code = 'ENG-LOC'");
    $pdo->exec("DELETE b FROM stock_import_batches b JOIN locations l ON l.id = b.location_id WHERE l.code = 'ENG-LOC'");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_id IN (SELECT id FROM users WHERE username LIKE 'eng_%')");
    $pdo->exec("DELETE FROM items WHERE sku LIKE 'ENG-%'");
    $pdo->exec("DELETE FROM categories WHERE code = 'ENG-CAT'");
    $pdo->exec("DELETE FROM locations WHERE code = 'ENG-LOC'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'eng_%'");
}
