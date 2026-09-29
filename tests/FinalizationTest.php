<?php
declare(strict_types=1);

/**
 * Go-live MVP: FinalizationService — auto-MATCH bulk finalize, manual final
 * for MISMATCH, ACTIVE->REVIEW->FINISHED state machine, and the two
 * preflight gates. Exercised directly against the real MariaDB instance,
 * same pattern as tests/EngineTest.php.
 */
function test_finalization(): void
{
    $pdo = Database::pdo();
    cleanupFinalizationFixtures($pdo);

    try {
        $pdo->prepare("INSERT INTO categories (code, name, status) VALUES ('FIN-CAT','Finalization Test Category','ACTIVE')")->execute();
        $categoryId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO locations (code, name, status) VALUES ('FIN-LOC','Finalization Test Location','ACTIVE')")->execute();
        $locationId = (int) $pdo->lastInsertId();

        $itemIds = [];
        $itemSpecs = [
            // sku, name, buy_unit, buy_content, base_unit, last_buy_price
            ['FIN-A', 'Item A (will MATCH)', 'Pcs', 1, 'Pcs', 1000],
            ['FIN-B', 'Item B (will MISMATCH)', 'Pcs', 1, 'Pcs', 500],
            ['FIN-C', 'Item C (NOT_COUNTABLE)', 'Pcs', 1, 'Pcs', 100],
            ['FIN-D', 'Item D (MATCH, no cost)', 'Pcs', 1, 'Pcs', null],
            ['FIN-E', 'Item E (never counted -> NOT_COUNTABLE too)', 'Pcs', 1, 'Pcs', 100],
        ];
        $insItem = $pdo->prepare(
            'INSERT INTO items (sku, name, category_id, buy_unit, buy_content, base_unit, last_buy_price, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'ACTIVE\')'
        );
        foreach ($itemSpecs as [$sku, $name, $buyUnit, $buyContent, $baseUnit, $price]) {
            $insItem->execute([$sku, $name, $categoryId, $buyUnit, $buyContent, $baseUnit, $price]);
            $itemIds[$sku] = (int) $pdo->lastInsertId();
        }

        $superadminId = ensureFinUser($pdo, 'fin_superadmin', 'SUPERADMIN', null);
        $p1Id = ensureFinUser($pdo, 'fin_p1', 'COUNTER', 'P1');
        $p2Id = ensureFinUser($pdo, 'fin_p2', 'COUNTER', 'P2');

        $csv = sys_get_temp_dir() . '/fin_test_import.csv';
        file_put_contents(
            $csv,
            "sku,system_qty_base,unit_cost\nFIN-A,100,1000\nFIN-B,200,500\nFIN-C,10,100\nFIN-D,50,\nFIN-E,10,100\n"
        );
        $importService = new StockImportService($pdo);
        $preview = $importService->previewCsv($locationId, $csv, 'fin.csv', $superadminId);
        $importService->commit($preview['batch_id'], $superadminId);

        $locks = new ItemLockService($pdo, 300);
        $sessions = new SessionService($pdo);
        $evidence = new PhotoEvidenceService($pdo, $locks, sys_get_temp_dir() . '/fin_test_uploads', 8192);
        $counts = new CountService($pdo, $locks, $evidence);
        $recon = new ReconciliationService($pdo, $locks);
        $fin = new FinalizationService($pdo, $recon, $locks);

        $session = $sessions->createSession(
            ['name' => 'Finalization Test Session', 'location_id' => $locationId, 'scope_type' => 'CATEGORY', 'category_id' => $categoryId],
            $superadminId
        );
        $sessions->assignCounter((int) $session['id'], $p1Id, 'P1', $superadminId);
        $sessions->assignCounter((int) $session['id'], $p2Id, 'P2', $superadminId);
        $sessions->startSession((int) $session['id'], $superadminId);
        $sessionId = (int) $session['id'];

        $siStmt = $pdo->prepare('SELECT * FROM stock_opname_session_items WHERE session_id = ? AND sku_snapshot = ?');
        $siId = function (string $sku) use ($siStmt, $sessionId): int {
            $siStmt->execute([$sessionId, $sku]);
            return (int) $siStmt->fetch()['id'];
        };
        $aId = $siId('FIN-A');
        $bId = $siId('FIN-B');
        $cId = $siId('FIN-C');
        $dId = $siId('FIN-D');
        $eId = $siId('FIN-E');

        // ------------------------------------------------------------
        T::section('FinalizationService — setup: count A/B/D, NOT_COUNTABLE for C, leave E uncounted');
        // ------------------------------------------------------------

        foreach ([[$aId, 100, 100], [$bId, 190, 195], [$dId, 50, 50]] as [$siId2, $p1Qty, $p2Qty]) {
            $locks->acquire($siId2, 'P1', $p1Id);
            $counts->saveCount($siId2, $p1Id, ['good_buy_qty' => 0, 'good_mid_qty' => 0, 'good_base_input_qty' => $p1Qty, 'damaged_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 0]);
            $locks->acquire($siId2, 'P2', $p2Id);
            $counts->saveCount($siId2, $p2Id, ['good_buy_qty' => 0, 'good_mid_qty' => 0, 'good_base_input_qty' => $p2Qty, 'damaged_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 0]);
        }
        $recon->setNotCountable($cId, $superadminId, 'Barang dipindahkan ke gudang lain sebelum opname');

        $rows = $recon->listForReview($sessionId);
        $statusBySku = [];
        foreach ($rows as $r) {
            $statusBySku[$r['session_item']['sku_snapshot']] = $r['status'];
        }
        T::assertEquals('MATCH', $statusBySku['FIN-A'], 'FIN-A: identical P1/P2 counts -> MATCH');
        T::assertEquals('MISMATCH', $statusBySku['FIN-B'], 'FIN-B: differing totals -> MISMATCH');
        T::assertEquals('NOT_COUNTABLE', $statusBySku['FIN-C'], 'FIN-C -> NOT_COUNTABLE');
        T::assertEquals('MATCH', $statusBySku['FIN-D'], 'FIN-D: identical counts, no cost -> still MATCH');
        T::assertEquals('BELUM_DIHITUNG', $statusBySku['FIN-E'], 'FIN-E never counted -> BELUM_DIHITUNG');

        // ------------------------------------------------------------
        T::section('FinalizationService — actions rejected before REVIEW');
        // ------------------------------------------------------------

        $rejectedActive = false;
        try {
            $fin->setFinal($aId, $superadminId, ['good' => 100, 'damaged' => 0, 'expired' => 0, 'deadstock' => 0], 'test');
        } catch (RuntimeException $e) {
            $rejectedActive = true;
        }
        T::assertTrue($rejectedActive, 'setFinal() rejected while session is still ACTIVE');

        $bulkRejectedActive = false;
        try {
            $fin->bulkFinalizeMatch($sessionId, $superadminId);
        } catch (RuntimeException $e) {
            $bulkRejectedActive = true;
        }
        T::assertTrue($bulkRejectedActive, 'bulkFinalizeMatch() rejected while session is still ACTIVE');

        // ------------------------------------------------------------
        T::section('FinalizationService — reviewPreflight() blocks on an uncounted item');
        // ------------------------------------------------------------

        $blockers1 = $fin->reviewPreflight($sessionId);
        T::assertTrue(count($blockers1) > 0, 'reviewPreflight() blocks while FIN-E is still BELUM_DIHITUNG');
        T::assertTrue(str_contains(implode(' ', $blockers1), '1 item belum selesai'), 'Blocker names exactly 1 not-ready item');

        $transitionBlocked = false;
        try {
            $fin->transitionToReview($sessionId, $superadminId);
        } catch (FinalizationPreflightException $e) {
            $transitionBlocked = true;
            T::assertTrue(count($e->blockers) > 0, 'FinalizationPreflightException carries the blocker list');
        }
        T::assertTrue($transitionBlocked, 'transitionToReview() itself refuses to move ACTIVE -> REVIEW while blocked');

        $recon->setNotCountable($eId, $superadminId, 'Rak kosong, item non-aktif musiman');
        $blockers2 = $fin->reviewPreflight($sessionId);
        T::assertEquals([], $blockers2, 'reviewPreflight() clean once FIN-E is resolved (marked NOT_COUNTABLE)');

        // ------------------------------------------------------------
        T::section('FinalizationService — ACTIVE -> REVIEW');
        // ------------------------------------------------------------

        $reviewed = $fin->transitionToReview($sessionId, $superadminId);
        T::assertEquals('REVIEW', $reviewed['status'], 'Session moves to REVIEW');
        T::assertTrue($reviewed['review_started_at'] !== null, 'review_started_at recorded');
        T::assertEquals($superadminId, (int) $reviewed['review_started_by'], 'review_started_by recorded');

        // ------------------------------------------------------------
        T::section('FinalizationService — finishPreflight() blocks before any finals exist');
        // ------------------------------------------------------------

        $fpBlockers1 = $fin->finishPreflight($sessionId);
        T::assertTrue(count($fpBlockers1) > 0, 'finishPreflight() blocks: NORMAL items have no final yet');

        $finishBlocked = false;
        try {
            $fin->finishSession($sessionId, $superadminId);
        } catch (FinalizationPreflightException $e) {
            $finishBlocked = true;
        }
        T::assertTrue($finishBlocked, 'finishSession() itself refuses while finals are missing');

        // ------------------------------------------------------------
        T::section('FinalizationService — bulkFinalizeMatch() auto-finalizes MATCH items only');
        // ------------------------------------------------------------

        $bulkResult = $fin->bulkFinalizeMatch($sessionId, $superadminId);
        T::assertEquals(2, $bulkResult['finalized'], 'Exactly FIN-A and FIN-D auto-finalized (both MATCH); FIN-B (MISMATCH) is not');
        T::assertEquals(0, $bulkResult['skipped'], 'Nothing skipped on first run (no pre-existing finals)');

        $finalA = $fin->currentFinal($aId);
        T::assertTrue($finalA !== null, 'FIN-A now has a current final');
        T::assertEquals('AUTO_MATCH', $finalA['source'], 'FIN-A final tagged source=AUTO_MATCH');
        T::assertEquals(100.0, (float) $finalA['final_good_base_qty'], 'FIN-A final good = 100 (from the agreeing P1/P2 count)');
        T::assertEquals(100.0, (float) $finalA['final_physical_base_qty'], 'FIN-A physical = good+damaged+expired+deadstock = 100');
        T::assertEquals(100.0, (float) $finalA['final_available_base_qty'], 'FIN-A available = good = 100');
        T::assertEquals(0.0, (float) $finalA['variance_qty'], 'FIN-A variance = available(100) - system_qty(100) = 0');
        T::assertEquals(0.0, (float) $finalA['variance_value'], 'FIN-A variance_value = 0 * 1000 = 0');

        $finalD = $fin->currentFinal($dId);
        T::assertTrue($finalD !== null, 'FIN-D now has a current final');
        T::assertTrue($finalD['variance_value'] === null, 'FIN-D variance_value stays NULL — unit_cost_snapshot is genuinely unknown, never coerced to 0');

        $finalB = $fin->currentFinal($bId);
        T::assertTrue($finalB === null, 'FIN-B (MISMATCH) was correctly skipped by bulk auto-finalize');

        $bulkResult2 = $fin->bulkFinalizeMatch($sessionId, $superadminId);
        T::assertEquals(0, $bulkResult2['finalized'], 'Re-running bulk finalize finalizes nothing new');
        T::assertEquals(2, $bulkResult2['skipped'], 'Re-running bulk finalize reports both already-final MATCH items as skipped, not re-finalized');

        // ------------------------------------------------------------
        T::section('FinalizationService — setFinal() manual resolution for MISMATCH');
        // ------------------------------------------------------------

        $emptyReasonRejected = false;
        try {
            $fin->setFinal($bId, $superadminId, ['good' => 193, 'damaged' => 2, 'expired' => 0, 'deadstock' => 0], '');
        } catch (InvalidArgumentException $e) {
            $emptyReasonRejected = true;
        }
        T::assertTrue($emptyReasonRejected, 'setFinal() requires a non-empty reason');

        $negativeRejected = false;
        try {
            $fin->setFinal($bId, $superadminId, ['good' => -1, 'damaged' => 0, 'expired' => 0, 'deadstock' => 0], 'test');
        } catch (InvalidArgumentException $e) {
            $negativeRejected = true;
        }
        T::assertTrue($negativeRejected, 'setFinal() rejects a negative quantity');

        $notCountableRejected = false;
        try {
            $fin->setFinal($cId, $superadminId, ['good' => 1, 'damaged' => 0, 'expired' => 0, 'deadstock' => 0], 'salah target');
        } catch (RuntimeException $e) {
            $notCountableRejected = true;
        }
        T::assertTrue($notCountableRejected, 'setFinal() rejects a NOT_COUNTABLE item — nothing was measured to finalize');

        $finalBRow = $fin->setFinal($bId, $superadminId, ['good' => 193, 'damaged' => 2, 'expired' => 0, 'deadstock' => 0], 'Diputuskan setelah verifikasi fisik ulang oleh Superadmin');
        T::assertEquals(1, (int) $finalBRow['version'], 'First manual final on FIN-B is version 1');
        T::assertEquals('MANUAL', $finalBRow['source'], 'Manual final tagged source=MANUAL');
        T::assertEquals(195.0, (float) $finalBRow['final_physical_base_qty'], 'FIN-B physical = 193 good + 2 damaged = 195');
        T::assertEquals(193.0, (float) $finalBRow['final_available_base_qty'], 'FIN-B available = good only = 193');
        T::assertEquals(-7.0, (float) $finalBRow['variance_qty'], 'FIN-B variance = 193 - system_qty(200) = -7');
        T::assertEquals(-3500.0, (float) $finalBRow['variance_value'], 'FIN-B variance_value = -7 * 500 = -3500');

        // Re-setting a final is append-only versioning, never an overwrite.
        $finalBRow2 = $fin->setFinal($bId, $superadminId, ['good' => 194, 'damaged' => 1, 'expired' => 0, 'deadstock' => 0], 'Koreksi kecil setelah re-cek fisik');
        T::assertEquals(2, (int) $finalBRow2['version'], 'Second manual final on FIN-B is version 2');
        $history = $fin->finalHistory($bId);
        T::assertEquals(2, count($history), 'finalHistory() returns both versions');
        T::assertEquals(0, (int) $history[0]['is_current'], 'Old version 1 no longer marked is_current');
        T::assertEquals(1, (int) $history[1]['is_current'], 'New version 2 is the current one');
        T::assertEquals($finalBRow2['id'], $fin->currentFinal($bId)['id'], 'currentFinal() now returns version 2');

        // ------------------------------------------------------------
        T::section('FinalizationService — REVIEW -> FINISHED');
        // ------------------------------------------------------------

        $fpBlockers2 = $fin->finishPreflight($sessionId);
        T::assertEquals([], $fpBlockers2, 'finishPreflight() clean: A/B/D finalized, C/E are NOT_COUNTABLE with reasons');

        $finished = $fin->finishSession($sessionId, $superadminId);
        T::assertEquals('FINISHED', $finished['status'], 'Session moves to FINISHED');
        T::assertTrue($finished['finished_at'] !== null, 'finished_at recorded');
        T::assertEquals($superadminId, (int) $finished['finished_by'], 'finished_by recorded');

        $doubleFinishBlocked = false;
        try {
            $fin->finishSession($sessionId, $superadminId);
        } catch (RuntimeException $e) {
            $doubleFinishBlocked = true;
        }
        T::assertTrue($doubleFinishBlocked, 'finishSession() on an already-FINISHED session is rejected');

        $auditCheck = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action IN ('SESSION_TO_REVIEW','SESSION_BULK_FINALIZE_MATCH','FINAL_SET','SESSION_FINISH') AND entity_id IN (?, ?, ?)");
        $auditCheck->execute([$sessionId, $bId, $aId]);
        T::assertTrue((int) $auditCheck->fetchColumn() >= 4, 'Every finalization action is audit logged');
    } finally {
        cleanupFinalizationFixtures($pdo);
    }
}

function ensureFinUser(PDO $pdo, string $username, string $role, ?string $team): int
{
    $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, role, team, status) VALUES (?, ?, ?, ?, ?, \'ACTIVE\')'
    );
    $stmt->execute([$username, password_hash('irrelevant', PASSWORD_DEFAULT), ucfirst($username), $role, $team]);
    return (int) $pdo->lastInsertId();
}

function cleanupFinalizationFixtures(PDO $pdo): void
{
    $pdo->exec("DELETE f FROM stock_opname_finals f
                JOIN stock_opname_session_items si ON si.id = f.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'FIN-LOC'");
    $pdo->exec("DELETE l FROM stock_opname_item_locks l
                JOIN stock_opname_session_items si ON si.id = l.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'FIN-LOC'");
    $pdo->exec("DELETE cr FROM stock_opname_count_revisions cr
                JOIN stock_opname_counts c ON c.id = cr.count_id
                JOIN stock_opname_session_items si ON si.id = c.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'FIN-LOC'");
    $pdo->exec("DELETE c FROM stock_opname_counts c
                JOIN stock_opname_session_items si ON si.id = c.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'FIN-LOC'");
    $pdo->exec("DELETE rc FROM stock_opname_recounts rc
                JOIN stock_opname_session_items si ON si.id = rc.session_item_id
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'FIN-LOC'");
    $pdo->exec("DELETE si FROM stock_opname_session_items si
                JOIN stock_opname_sessions s ON s.id = si.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'FIN-LOC'");
    $pdo->exec("DELETE sc FROM stock_opname_session_counters sc
                JOIN stock_opname_sessions s ON s.id = sc.session_id
                JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'FIN-LOC'");
    $pdo->exec("DELETE al FROM audit_logs al WHERE al.entity_type = 'stock_opname_sessions'
                AND al.entity_id IN (SELECT s.id FROM stock_opname_sessions s JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'FIN-LOC')");
    $pdo->exec("DELETE al FROM audit_logs al WHERE al.entity_type = 'stock_opname_session_items'
                AND al.entity_id IN (SELECT si.id FROM stock_opname_session_items si
                    JOIN stock_opname_sessions s ON s.id = si.session_id
                    JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'FIN-LOC')");
    $pdo->exec("DELETE s FROM stock_opname_sessions s JOIN locations loc ON loc.id = s.location_id WHERE loc.code = 'FIN-LOC'");
    $pdo->exec("DELETE a FROM item_stock_adjustments a JOIN item_stock s ON s.id = a.item_stock_id JOIN items i ON i.id = s.item_id WHERE i.sku LIKE 'FIN-%'");
    $pdo->exec("DELETE s FROM item_stock s JOIN items i ON i.id = s.item_id WHERE i.sku LIKE 'FIN-%'");
    $pdo->exec("DELETE r FROM stock_import_rows r JOIN stock_import_batches b ON b.id = r.batch_id JOIN locations l ON l.id = b.location_id WHERE l.code = 'FIN-LOC'");
    $pdo->exec("DELETE b FROM stock_import_batches b JOIN locations l ON l.id = b.location_id WHERE l.code = 'FIN-LOC'");
    $pdo->exec("DELETE FROM audit_logs WHERE actor_id IN (SELECT id FROM users WHERE username LIKE 'fin_%')");
    $pdo->exec("DELETE FROM items WHERE sku LIKE 'FIN-%'");
    $pdo->exec("DELETE FROM categories WHERE code = 'FIN-CAT'");
    $pdo->exec("DELETE FROM locations WHERE code = 'FIN-LOC'");
    $pdo->exec("DELETE FROM users WHERE username LIKE 'fin_%'");
}
