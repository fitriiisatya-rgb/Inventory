<?php
declare(strict_types=1);

final class FinalizationPreflightException extends RuntimeException
{
    /** @param array<int,string> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('Preflight failed: ' . implode('; ', $blockers));
    }
}

/**
 * Go-live MVP: session finalization (ACTIVE -> REVIEW -> FINISHED),
 * per-item final quantities (auto for MATCH, manual for everything else),
 * and the finish preflight gate. Built on top of the already-verified
 * Phase 4/5 engine (ReconciliationService, ItemLockService) — this class
 * adds no new counting/locking/evidence logic, only the closing workflow.
 */
final class FinalizationService
{
    public function __construct(private PDO $pdo, private ReconciliationService $recon, private ItemLockService $locks)
    {
    }

    // ------------------------------------------------------------------
    // Per-item final (append-only, versioned — mirrors stock_opname_counts
    // revisions: a new row is inserted, the old one's is_current flips to 0,
    // nothing is ever overwritten or deleted).
    // ------------------------------------------------------------------

    public function currentFinal(int $sessionItemId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM stock_opname_finals WHERE session_item_id = ? AND is_current = 1 LIMIT 1'
        );
        $stmt->execute([$sessionItemId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array> all versions, oldest first */
    public function finalHistory(int $sessionItemId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.*, u.full_name AS set_by_name FROM stock_opname_finals f
             JOIN users u ON u.id = f.set_by
             WHERE f.session_item_id = ? ORDER BY f.version'
        );
        $stmt->execute([$sessionItemId]);
        return $stmt->fetchAll();
    }

    /**
     * Manual final: SUPERADMIN sets an adjudicated per-condition breakdown
     * for one item (used to resolve MISMATCH/CONDITION_MISMATCH/PARTIAL, or
     * to override an auto-finalized MATCH). Requires session REVIEW and a
     * non-empty reason — a final is a permanent record of a decision, not
     * a casual edit.
     */
    public function setFinal(int $sessionItemId, int $actorId, array $values, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('reason wajib diisi untuk menetapkan final.');
        }
        $good = $this->nonNegative($values['good'] ?? null, 'good');
        $damaged = $this->nonNegative($values['damaged'] ?? null, 'damaged');
        $expired = $this->nonNegative($values['expired'] ?? null, 'expired');
        $deadstock = $this->nonNegative($values['deadstock'] ?? null, 'deadstock');

        return $this->writeFinal($sessionItemId, $actorId, $good, $damaged, $expired, $deadstock, $reason, 'MANUAL');
    }

    private function nonNegative(mixed $v, string $field): float
    {
        if ($v === null || !is_numeric($v)) {
            throw new InvalidArgumentException("{$field} wajib diisi dengan angka.");
        }
        $f = (float) $v;
        if ($f < 0) {
            throw new InvalidArgumentException("{$field} tidak boleh negatif.");
        }
        return $f;
    }

    /** Opens its own transaction — used by the single-item path (setFinal()). */
    private function writeFinal(
        int $sessionItemId, int $actorId, float $good, float $damaged, float $expired, float $deadstock,
        string $reason, string $source
    ): array {
        $this->pdo->beginTransaction();
        try {
            $row = $this->writeFinalNoTransaction($sessionItemId, $actorId, $good, $damaged, $expired, $deadstock, $reason, $source);
            $this->pdo->commit();
            return $row;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Core write, assumes the caller already holds an open transaction
     * (never opens or closes one itself) — used by bulkFinalizeMatch() so
     * the whole batch commits or rolls back as a single unit, and by
     * writeFinal() above for the single-item path.
     */
    private function writeFinalNoTransaction(
        int $sessionItemId, int $actorId, float $good, float $damaged, float $expired, float $deadstock,
        string $reason, string $source
    ): array {
        $siStmt = $this->pdo->prepare(
            'SELECT si.*, s.status AS session_status FROM stock_opname_session_items si
             JOIN stock_opname_sessions s ON s.id = si.session_id
             WHERE si.id = ? FOR UPDATE'
        );
        $siStmt->execute([$sessionItemId]);
        $si = $siStmt->fetch();
        if (!$si) {
            throw new RuntimeException('Item sesi tidak ditemukan.');
        }
        if ($si['session_status'] !== 'REVIEW') {
            throw new RuntimeException("Final hanya dapat ditetapkan selama session REVIEW (saat ini: {$si['session_status']}).");
        }
        if ($si['item_status'] !== 'NORMAL') {
            throw new RuntimeException('Item berstatus ' . $si['item_status'] . ' tidak memiliki final (tidak diukur).');
        }

        $physical = $good + $damaged + $expired + $deadstock;
        $available = $good;
        $systemQty = (float) $si['system_qty_snapshot'];
        $varianceQty = $available - $systemQty;
        $unitCost = $si['unit_cost_snapshot'] !== null ? (float) $si['unit_cost_snapshot'] : null;
        $varianceValue = $unitCost !== null ? round($varianceQty * $unitCost, 2) : null;

        $existing = $this->currentFinal($sessionItemId);
        $nextVersion = $existing ? ((int) $existing['version']) + 1 : 1;

        if ($existing) {
            $this->pdo->prepare('UPDATE stock_opname_finals SET is_current = 0 WHERE id = ?')->execute([$existing['id']]);
        }

        $this->pdo->prepare(
            'INSERT INTO stock_opname_finals
                (session_item_id, version, final_good_base_qty, final_damaged_base_qty, final_expired_base_qty,
                 final_deadstock_base_qty, final_physical_base_qty, final_available_base_qty, source,
                 final_qty, variance_qty, variance_value, reason, set_by, is_current)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $sessionItemId, $nextVersion, $good, $damaged, $expired, $deadstock, $physical, $available, $source,
            $physical, $varianceQty, $varianceValue, $reason, $actorId,
        ]);
        $finalId = (int) $this->pdo->lastInsertId();

        Audit::log($actorId, 'FINAL_SET', 'stock_opname_session_items', $sessionItemId, $existing ?: null, [
            'good' => $good, 'damaged' => $damaged, 'expired' => $expired, 'deadstock' => $deadstock,
            'physical' => $physical, 'available' => $available, 'variance_qty' => $varianceQty,
            'reason' => $reason, 'source' => $source, 'version' => $nextVersion,
        ]);

        return $this->pdo->query("SELECT * FROM stock_opname_finals WHERE id = {$finalId}")->fetch();
    }

    /**
     * Auto-finalizes every item currently MATCH (P1 == P2, clean agreement)
     * that does not already have a current final. Both teams agreed, so
     * their own breakdown IS the final — no separate SUPERADMIN judgment
     * call is needed for these; the "resolution" is that they matched.
     * Runs as one transaction so the confirmation count the caller showed
     * the user ("N item akan di-finalize") is exactly what gets written.
     *
     * @return array{finalized: int, skipped: int, session_item_ids: array<int,int>}
     */
    public function bulkFinalizeMatch(int $sessionId, int $actorId): array
    {
        $session = $this->findSession($sessionId);
        if (!$session) {
            throw new RuntimeException('Session tidak ditemukan.');
        }
        if ($session['status'] !== 'REVIEW') {
            throw new RuntimeException("Bulk finalize hanya dapat dijalankan selama session REVIEW (saat ini: {$session['status']}).");
        }

        $rows = $this->recon->listForReview($sessionId);
        $finalized = [];
        $skipped = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                if ($r['status'] !== 'MATCH') {
                    continue;
                }
                $sessionItemId = (int) $r['session_item']['id'];
                if ($this->currentFinal($sessionItemId) !== null) {
                    $skipped++;
                    continue;
                }
                $p1 = $r['p1'];
                $this->writeFinalNoTransaction(
                    $sessionItemId, $actorId,
                    (float) $p1['good_base_qty'], (float) $p1['damaged_base_qty'],
                    (float) $p1['expired_base_qty'], (float) $p1['deadstock_base_qty'],
                    'Auto-finalisasi: P1 dan P2 sepakat (MATCH).', 'AUTO_MATCH'
                );
                $finalized[] = $sessionItemId;
            }

            Audit::log($actorId, 'SESSION_BULK_FINALIZE_MATCH', 'stock_opname_sessions', $sessionId, null, [
                'finalized' => count($finalized), 'skipped' => $skipped,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['finalized' => count($finalized), 'skipped' => $skipped, 'session_item_ids' => $finalized];
    }

    // ------------------------------------------------------------------
    // State machine: ACTIVE -> REVIEW -> FINISHED
    // ------------------------------------------------------------------

    private function findSession(int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<int,string> blockers preventing ACTIVE -> REVIEW (empty = clear)
     */
    public function reviewPreflight(int $sessionId): array
    {
        $blockers = [];
        $session = $this->findSession($sessionId);
        if (!$session) {
            return ['Session tidak ditemukan.'];
        }
        if ($session['status'] !== 'ACTIVE') {
            $blockers[] = "Session harus berstatus ACTIVE untuk masuk REVIEW (saat ini: {$session['status']}).";
        }

        $rows = $this->recon->listForReview($sessionId);
        $notReady = 0;
        $evidencePending = 0;
        foreach ($rows as $r) {
            $status = $r['status'];
            if (in_array($status, ['BELUM_DIHITUNG', 'PARTIAL', 'RECOUNT_REQUIRED'], true)) {
                $notReady++;
            }
            if ($r['p1_evidence_pending'] || $r['p2_evidence_pending']) {
                $evidencePending++;
            }
        }
        if ($notReady > 0) {
            $blockers[] = "{$notReady} item belum selesai dihitung oleh kedua tim (BELUM_DIHITUNG/PARTIAL/RECOUNT_REQUIRED).";
        }
        if ($evidencePending > 0) {
            $blockers[] = "{$evidencePending} item masih menunggu foto bukti (EVIDENCE_REQUIRED).";
        }

        $lockStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM stock_opname_item_locks l
             JOIN stock_opname_session_items si ON si.id = l.session_item_id
             WHERE si.session_id = ?'
        );
        $lockStmt->execute([$sessionId]);
        $lockCount = (int) $lockStmt->fetchColumn();
        if ($lockCount > 0) {
            $blockers[] = "{$lockCount} item masih dikunci (sedang dihitung) oleh petugas.";
        }

        return $blockers;
    }

    public function transitionToReview(int $sessionId, int $actorId): array
    {
        $this->pdo->beginTransaction();
        try {
            $lockStmt = $this->pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$sessionId]);
            $session = $lockStmt->fetch();
            if (!$session) {
                throw new RuntimeException('Session tidak ditemukan.');
            }
            $blockers = $this->reviewPreflight($sessionId);
            if (!empty($blockers)) {
                throw new FinalizationPreflightException($blockers);
            }

            $this->pdo->prepare(
                "UPDATE stock_opname_sessions SET status = 'REVIEW', review_started_at = NOW(), review_started_by = ? WHERE id = ?"
            )->execute([$actorId, $sessionId]);

            Audit::log($actorId, 'SESSION_TO_REVIEW', 'stock_opname_sessions', $sessionId, ['status' => 'ACTIVE'], ['status' => 'REVIEW']);

            $this->pdo->commit();
            return $this->findSession($sessionId);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<int,string> blockers preventing REVIEW -> FINISHED (empty = clear)
     */
    public function finishPreflight(int $sessionId): array
    {
        $blockers = [];
        $session = $this->findSession($sessionId);
        if (!$session) {
            return ['Session tidak ditemukan.'];
        }
        if ($session['status'] !== 'REVIEW') {
            $blockers[] = "Session harus berstatus REVIEW untuk diselesaikan (saat ini: {$session['status']}).";
        }

        $lockStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM stock_opname_item_locks l
             JOIN stock_opname_session_items si ON si.id = l.session_item_id
             WHERE si.session_id = ?'
        );
        $lockStmt->execute([$sessionId]);
        $lockCount = (int) $lockStmt->fetchColumn();
        if ($lockCount > 0) {
            $blockers[] = "{$lockCount} item masih dikunci.";
        }

        $rows = $this->recon->listForReview($sessionId);
        $evidencePending = 0;
        $recountRequired = 0;
        $missingFinal = 0;
        $missingReason = 0;
        foreach ($rows as $r) {
            $si = $r['session_item'];
            if ($si['item_status'] === 'NOT_COUNTABLE') {
                if (empty($si['not_countable_reason'])) {
                    $missingReason++;
                }
                continue;
            }
            if ($r['p1_evidence_pending'] || $r['p2_evidence_pending']) {
                $evidencePending++;
            }
            if ($r['status'] === 'RECOUNT_REQUIRED') {
                $recountRequired++;
            }
            if ($this->currentFinal((int) $si['id']) === null) {
                $missingFinal++;
            }
        }
        if ($evidencePending > 0) {
            $blockers[] = "{$evidencePending} item masih menunggu foto bukti (EVIDENCE_REQUIRED).";
        }
        if ($recountRequired > 0) {
            $blockers[] = "{$recountRequired} item masih menunggu hasil hitung ulang.";
        }
        if ($missingFinal > 0) {
            $blockers[] = "{$missingFinal} item NORMAL belum memiliki final (termasuk yang MATCH tapi belum di-finalize — gunakan bulk-finalize atau tetapkan manual).";
        }
        if ($missingReason > 0) {
            $blockers[] = "{$missingReason} item NOT_COUNTABLE tidak memiliki reason.";
        }

        return $blockers;
    }

    public function finishSession(int $sessionId, int $actorId): array
    {
        $this->pdo->beginTransaction();
        try {
            $lockStmt = $this->pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$sessionId]);
            $session = $lockStmt->fetch();
            if (!$session) {
                throw new RuntimeException('Session tidak ditemukan.');
            }
            $blockers = $this->finishPreflight($sessionId);
            if (!empty($blockers)) {
                throw new FinalizationPreflightException($blockers);
            }

            $this->pdo->prepare(
                "UPDATE stock_opname_sessions SET status = 'FINISHED', finished_at = NOW(), finished_by = ? WHERE id = ?"
            )->execute([$actorId, $sessionId]);

            Audit::log($actorId, 'SESSION_FINISH', 'stock_opname_sessions', $sessionId, ['status' => 'REVIEW'], ['status' => 'FINISHED']);

            $this->pdo->commit();
            return $this->findSession($sessionId);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
