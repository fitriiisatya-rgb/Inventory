<?php
declare(strict_types=1);

/**
 * Per-team item locking (design review points 9-11). Atomicity comes
 * from the UNIQUE(session_item_id, team) constraint on
 * stock_opname_item_locks itself, not from an application-level
 * transaction — two truly simultaneous INSERT attempts for the same
 * (session_item_id, team) can never both succeed; InnoDB serializes
 * them and the loser gets a duplicate-key error, which this class turns
 * into a normal "locked by someone else" result. No SELECT-then-INSERT
 * race window exists because there is no SELECT in the decision path.
 */
final class ItemLockService
{
    public function __construct(private PDO $pdo, private int $ttlSeconds)
    {
    }

    /**
     * @return array{ok: bool, lock_id?: int, expires_at?: string, locked_by?: array}
     */
    public function acquire(int $sessionItemId, string $team, int $userId): array
    {
        // Reap an expired lock for this exact slot first. This is a
        // plain autocommit DELETE — if a concurrent acquirer already
        // reaped it and inserted their own, our DELETE just matches 0
        // rows, which is fine.
        $this->pdo->prepare('DELETE FROM stock_opname_item_locks WHERE session_item_id = ? AND team = ? AND expires_at < NOW()')
            ->execute([$sessionItemId, $team]);

        $expiresAt = date('Y-m-d H:i:s', time() + $this->ttlSeconds);
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO stock_opname_item_locks (session_item_id, team, user_id, locked_at, expires_at, heartbeat_at)
                 VALUES (?, ?, ?, NOW(), ?, NOW())'
            );
            $stmt->execute([$sessionItemId, $team, $userId, $expiresAt]);
            return ['ok' => true, 'lock_id' => (int) $this->pdo->lastInsertId(), 'expires_at' => $expiresAt];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $holder = $this->currentHolder($sessionItemId, $team);
            return ['ok' => false, 'locked_by' => $holder];
        }
    }

    public function heartbeat(int $sessionItemId, string $team, int $userId): bool
    {
        $expiresAt = date('Y-m-d H:i:s', time() + $this->ttlSeconds);
        $stmt = $this->pdo->prepare(
            'UPDATE stock_opname_item_locks SET heartbeat_at = NOW(), expires_at = ?
             WHERE session_item_id = ? AND team = ? AND user_id = ? AND expires_at >= NOW()'
        );
        $stmt->execute([$expiresAt, $sessionItemId, $team, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function release(int $sessionItemId, string $team, int $userId): void
    {
        $this->pdo->prepare('DELETE FROM stock_opname_item_locks WHERE session_item_id = ? AND team = ? AND user_id = ?')
            ->execute([$sessionItemId, $team, $userId]);
    }

    /** The valid (non-expired) lock a user currently holds for this slot, or null. */
    public function findOwnedBy(int $sessionItemId, string $team, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM stock_opname_item_locks
             WHERE session_item_id = ? AND team = ? AND user_id = ? AND expires_at >= NOW() LIMIT 1'
        );
        $stmt->execute([$sessionItemId, $team, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function currentHolder(int $sessionItemId, string $team): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, u.full_name FROM stock_opname_item_locks l
             JOIN users u ON u.id = l.user_id
             WHERE l.session_item_id = ? AND l.team = ? AND l.expires_at >= NOW() LIMIT 1'
        );
        $stmt->execute([$sessionItemId, $team]);
        return $stmt->fetch() ?: null;
    }

    /** Used by ReconciliationService::requestRecount() to clear both teams' locks on an item. */
    public function releaseAllForItem(int $sessionItemId): void
    {
        $this->pdo->prepare('DELETE FROM stock_opname_item_locks WHERE session_item_id = ?')->execute([$sessionItemId]);
    }
}
