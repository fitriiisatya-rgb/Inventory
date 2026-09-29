<?php
declare(strict_types=1);

/**
 * SUPERADMIN-only reconciliation, recount, and NOT_COUNTABLE workflow
 * (design review points 20-24). Deliberately exposes two SEPARATE status
 * vocabularies, computed by two separate methods, never derived from one
 * shared function with a "hide some fields" flag:
 *
 *   reviewStatus()  — SUPERADMIN: NOT_COUNTABLE, BELUM_DIHITUNG, PARTIAL,
 *                      MATCH, MISMATCH, CONDITION_MISMATCH, RECOUNT_REQUIRED
 *   counterStatus() — COUNTER (own team only): NOT_COUNTABLE, BELUM_DIHITUNG,
 *                      SEDANG_DIHITUNG, SUDAH_DIHITUNG, HITUNG_ULANG
 *
 * counterStatus() never looks at the other team's count at all — it is
 * architecturally incapable of leaking MATCH/MISMATCH/variance, not just
 * configured not to.
 */
final class ReconciliationService
{
    private const EPSILON = 0.0001;

    public function __construct(private PDO $pdo, private ItemLockService $locks)
    {
    }

    public static function floatEq(float $a, float $b): bool
    {
        return abs($a - $b) < self::EPSILON;
    }

    public function reviewStatus(array $sessionItem, ?array $p1, ?array $p2): string
    {
        if ($sessionItem['item_status'] === 'NOT_COUNTABLE') {
            return 'NOT_COUNTABLE';
        }
        $round = (int) $sessionItem['current_round'];

        if (!$p1 && !$p2) {
            return $round > 1 ? 'RECOUNT_REQUIRED' : 'BELUM_DIHITUNG';
        }
        if (!$p1 || !$p2) {
            return $round > 1 ? 'RECOUNT_REQUIRED' : 'PARTIAL';
        }

        $cleanMatch = self::floatEq((float) $p1['good_base_qty'], (float) $p2['good_base_qty'])
            && self::floatEq((float) $p1['damaged_base_qty'], (float) $p2['damaged_base_qty'])
            && self::floatEq((float) $p1['expired_base_qty'], (float) $p2['expired_base_qty'])
            && self::floatEq((float) $p1['deadstock_base_qty'], (float) $p2['deadstock_base_qty']);
        if ($cleanMatch) {
            return 'MATCH';
        }
        if (self::floatEq((float) $p1['physical_base_qty'], (float) $p2['physical_base_qty'])) {
            return 'CONDITION_MISMATCH';
        }
        return 'MISMATCH';
    }

    /**
     * Uses only this team's own count + this team's own lock state — never
     * reads the other team. A count that exists but still needs photo
     * evidence (Phase 5) is surfaced as its own distinct state, not shown
     * as SUDAH_DIHITUNG — the counter must see it's not actually done yet.
     */
    public function counterStatus(array $sessionItem, string $team, ?array $ownCount): string
    {
        if ($sessionItem['item_status'] === 'NOT_COUNTABLE') {
            return 'NOT_COUNTABLE';
        }
        if ($ownCount) {
            return $ownCount['evidence_status'] === 'EVIDENCE_REQUIRED' ? 'EVIDENCE_REQUIRED' : 'SUDAH_DIHITUNG';
        }
        $holder = $this->locks->currentHolder((int) $sessionItem['id'], $team);
        if ($holder) {
            return 'SEDANG_DIHITUNG';
        }
        return ((int) $sessionItem['current_round']) > 1 ? 'HITUNG_ULANG' : 'BELUM_DIHITUNG';
    }

    /**
     * @return array{session_item: array, p1: ?array, p2: ?array, status: string}
     *
     * A count still EVIDENCE_REQUIRED is treated as not-yet-submitted for
     * reconciliation purposes (design review point 16: "Jangan membuat
     * Superadmin reconciliation berjalan terhadap count incomplete") — the
     * WHERE clause below excludes it, so it reads as if p1/p2 were null,
     * not as a premature MATCH/MISMATCH against unfinished data.
     */
    public function listForReview(int $sessionId): array
    {
        $itemsStmt = $this->pdo->prepare('SELECT * FROM stock_opname_session_items WHERE session_id = ? ORDER BY sku_snapshot');
        $itemsStmt->execute([$sessionId]);
        $items = $itemsStmt->fetchAll();

        $countStmt = $this->pdo->prepare(
            "SELECT * FROM stock_opname_counts WHERE session_item_id = ? AND team = ? AND round = ? AND evidence_status = 'COMPLETE' LIMIT 1"
        );
        // Separate, non-gating lookup so a superadmin can tell "nobody has
        // counted this yet" apart from "counted, but photo evidence is
        // still pending" — informational only, never fed into reviewStatus().
        $pendingStmt = $this->pdo->prepare(
            "SELECT 1 FROM stock_opname_counts WHERE session_item_id = ? AND team = ? AND round = ? AND evidence_status = 'EVIDENCE_REQUIRED' LIMIT 1"
        );

        $result = [];
        foreach ($items as $si) {
            $countStmt->execute([$si['id'], 'P1', $si['current_round']]);
            $p1 = $countStmt->fetch() ?: null;
            $countStmt->execute([$si['id'], 'P2', $si['current_round']]);
            $p2 = $countStmt->fetch() ?: null;

            $pendingStmt->execute([$si['id'], 'P1', $si['current_round']]);
            $p1EvidencePending = (bool) $pendingStmt->fetchColumn();
            $pendingStmt->execute([$si['id'], 'P2', $si['current_round']]);
            $p2EvidencePending = (bool) $pendingStmt->fetchColumn();

            $result[] = [
                'session_item' => $si,
                'p1' => $p1,
                'p2' => $p2,
                'status' => $this->reviewStatus($si, $p1, $p2),
                'variance_p1' => $p1 ? (float) $p1['physical_base_qty'] - (float) $si['system_qty_snapshot'] : null,
                'variance_p2' => $p2 ? (float) $p2['physical_base_qty'] - (float) $si['system_qty_snapshot'] : null,
                'p1_evidence_pending' => $p1EvidencePending,
                'p2_evidence_pending' => $p2EvidencePending,
            ];
        }
        return $result;
    }

    /** @return array<string,int> */
    public function progressSummary(int $sessionId): array
    {
        $rows = $this->listForReview($sessionId);
        $summary = [
            'total' => count($rows), 'belum_dihitung' => 0, 'partial' => 0, 'match' => 0,
            'mismatch' => 0, 'condition_mismatch' => 0, 'recount_required' => 0, 'not_countable' => 0,
        ];
        foreach ($rows as $r) {
            $summary[strtolower($r['status'])] = ($summary[strtolower($r['status'])] ?? 0) + 1;
        }
        return $summary;
    }

    // ------------------------------------------------------------------
    // Recount (SUPERADMIN only)
    // ------------------------------------------------------------------

    public function requestRecount(int $sessionItemId, int $actorId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('reason wajib diisi untuk hitung ulang.');
        }

        $this->pdo->beginTransaction();
        try {
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
            if ($si['session_status'] !== 'ACTIVE') {
                throw new RuntimeException('Hitung ulang hanya dapat diminta selama session ACTIVE.');
            }
            if ($si['item_status'] !== 'NORMAL') {
                throw new RuntimeException('Item berstatus ' . $si['item_status'] . ', tidak dapat diminta hitung ulang.');
            }

            $round = (int) $si['current_round'];
            $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM stock_opname_counts WHERE session_item_id = ? AND round = ?');
            $countStmt->execute([$sessionItemId, $round]);
            if ((int) $countStmt->fetchColumn() === 0) {
                throw new RuntimeException('Belum ada hasil hitungan pada round ini — tidak ada yang perlu dihitung ulang.');
            }

            $newRound = $round + 1;
            $this->pdo->prepare(
                'INSERT INTO stock_opname_recounts (session_item_id, round_from, round_to, requested_by, reason)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$sessionItemId, $round, $newRound, $actorId, $reason]);

            $this->pdo->prepare('UPDATE stock_opname_session_items SET current_round = ? WHERE id = ?')
                ->execute([$newRound, $sessionItemId]);

            $this->locks->releaseAllForItem($sessionItemId);

            Audit::log($actorId, 'RECOUNT_REQUEST', 'stock_opname_session_items', $sessionItemId, ['round' => $round], ['round' => $newRound, 'reason' => $reason]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['session_item_id' => $sessionItemId, 'new_round' => $newRound];
    }

    // ------------------------------------------------------------------
    // NOT_COUNTABLE (SUPERADMIN only)
    // ------------------------------------------------------------------

    public function setNotCountable(int $sessionItemId, int $actorId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('reason wajib diisi.');
        }

        $siStmt = $this->pdo->prepare(
            'SELECT si.*, s.status AS session_status FROM stock_opname_session_items si
             JOIN stock_opname_sessions s ON s.id = si.session_id WHERE si.id = ? LIMIT 1'
        );
        $siStmt->execute([$sessionItemId]);
        $si = $siStmt->fetch();
        if (!$si) {
            throw new RuntimeException('Item sesi tidak ditemukan.');
        }
        if ($si['session_status'] !== 'ACTIVE') {
            throw new RuntimeException('NOT_COUNTABLE hanya dapat ditetapkan selama session ACTIVE.');
        }

        $this->pdo->prepare(
            "UPDATE stock_opname_session_items
             SET item_status = 'NOT_COUNTABLE', not_countable_reason = ?, not_countable_set_by = ?, not_countable_set_at = NOW()
             WHERE id = ?"
        )->execute([$reason, $actorId, $sessionItemId]);

        $this->locks->releaseAllForItem($sessionItemId);

        Audit::log($actorId, 'ITEM_NOT_COUNTABLE', 'stock_opname_session_items', $sessionItemId, ['item_status' => $si['item_status']], ['item_status' => 'NOT_COUNTABLE', 'reason' => $reason]);

        return ['session_item_id' => $sessionItemId, 'item_status' => 'NOT_COUNTABLE'];
    }

    public function clearNotCountable(int $sessionItemId, int $actorId): array
    {
        $siStmt = $this->pdo->prepare('SELECT * FROM stock_opname_session_items WHERE id = ? LIMIT 1');
        $siStmt->execute([$sessionItemId]);
        $si = $siStmt->fetch();
        if (!$si) {
            throw new RuntimeException('Item sesi tidak ditemukan.');
        }

        $this->pdo->prepare(
            "UPDATE stock_opname_session_items
             SET item_status = 'NORMAL', not_countable_reason = NULL, not_countable_set_by = NULL, not_countable_set_at = NULL
             WHERE id = ?"
        )->execute([$sessionItemId]);

        Audit::log($actorId, 'ITEM_NOT_COUNTABLE_CLEARED', 'stock_opname_session_items', $sessionItemId, ['item_status' => 'NOT_COUNTABLE'], ['item_status' => 'NORMAL']);

        return ['session_item_id' => $sessionItemId, 'item_status' => 'NORMAL'];
    }
}
