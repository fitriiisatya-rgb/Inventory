<?php
declare(strict_types=1);

final class SessionPreflightException extends RuntimeException
{
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('Session start preflight failed: ' . implode('; ', $blockers));
    }
}

/**
 * Session CRUD, counter assignment, start-session preflight, and the
 * atomic DRAFT->ACTIVE snapshot transaction (design review points 3-6).
 */
final class SessionService
{
    public function __construct(private PDO $pdo)
    {
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    /**
     * Auto-binds the latest COMMITTED stock_import_batch for the chosen
     * location as system_stock_batch_id (traceability, design review
     * point 3). A session can exist in DRAFT with no batch bound yet —
     * that's a start-session blocker, not a create-time error, since an
     * import might happen after the session is drafted but before it
     * starts.
     */
    public function createSession(array $data, int $actorId): array
    {
        $name       = trim((string) ($data['name'] ?? ''));
        $locationId = (int) ($data['location_id'] ?? 0);
        $scopeType  = in_array($data['scope_type'] ?? '', ['ALL', 'CATEGORY'], true) ? $data['scope_type'] : 'ALL';
        $categoryId = $scopeType === 'CATEGORY' ? (int) ($data['category_id'] ?? 0) : null;
        $physicalDate = $data['physical_date'] ?? null;

        if ($name === '' || $locationId <= 0) {
            throw new InvalidArgumentException('name dan location_id wajib diisi.');
        }
        if ($scopeType === 'CATEGORY' && $categoryId <= 0) {
            throw new InvalidArgumentException('category_id wajib diisi ketika scope_type = CATEGORY.');
        }

        $batchStmt = $this->pdo->prepare(
            "SELECT id FROM stock_import_batches WHERE location_id = ? AND status = 'COMMITTED' ORDER BY committed_at DESC LIMIT 1"
        );
        $batchStmt->execute([$locationId]);
        $batchId = $batchStmt->fetchColumn() ?: null;

        $sessionNo = $this->generateSessionNo();

        $stmt = $this->pdo->prepare(
            'INSERT INTO stock_opname_sessions
                (session_no, name, location_id, system_stock_batch_id, scope_type, category_id, physical_date, status, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'DRAFT\', ?)'
        );
        $stmt->execute([$sessionNo, $name, $locationId, $batchId, $scopeType, $categoryId, $physicalDate ?: null, $data['note'] ?? null]);
        $id = (int) $this->pdo->lastInsertId();

        $row = $this->find($id);
        Audit::log($actorId, 'SESSION_CREATE', 'stock_opname_sessions', $id, null, $row);
        return $row;
    }

    private function generateSessionNo(): string
    {
        $datePart = date('Ymd');
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM stock_opname_sessions WHERE session_no LIKE ?");
            $countStmt->execute(["SO-{$datePart}-%"]);
            $next = (int) $countStmt->fetchColumn() + $attempt; // + attempt to skip past a just-taken number on retry
            $candidate = sprintf('SO-%s-%04d', $datePart, $next);
            $existsStmt = $this->pdo->prepare('SELECT 1 FROM stock_opname_sessions WHERE session_no = ?');
            $existsStmt->execute([$candidate]);
            if (!$existsStmt->fetchColumn()) {
                return $candidate;
            }
        }
        throw new RuntimeException('Gagal generate session_no unik setelah beberapa percobaan.');
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function list(): array
    {
        return $this->pdo->query(
            'SELECT s.*, l.name AS location_name, c.name AS category_name
             FROM stock_opname_sessions s
             JOIN locations l ON l.id = s.location_id
             LEFT JOIN categories c ON c.id = s.category_id
             ORDER BY s.created_at DESC'
        )->fetchAll();
    }

    // ------------------------------------------------------------------
    // Counter assignment (DRAFT only — see Phase 4 report for rationale)
    // ------------------------------------------------------------------

    public function assignCounter(int $sessionId, int $userId, string $team, int $actorId): array
    {
        $session = $this->find($sessionId);
        if (!$session) {
            throw new RuntimeException('Session tidak ditemukan.');
        }
        if ($session['status'] !== 'DRAFT') {
            throw new RuntimeException('Assignment hanya dapat diubah selama session berstatus DRAFT.');
        }
        if (!in_array($team, ['P1', 'P2'], true)) {
            throw new InvalidArgumentException("team harus 'P1' atau 'P2'.");
        }

        $userStmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user || $user['role'] !== 'COUNTER') {
            throw new InvalidArgumentException('User yang di-assign harus memiliki role COUNTER.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO stock_opname_session_counters (session_id, user_id, team, status, assigned_by)
             VALUES (?, ?, ?, \'ACTIVE\', ?)
             ON DUPLICATE KEY UPDATE team = VALUES(team), status = \'ACTIVE\', assigned_by = VALUES(assigned_by), assigned_at = NOW()'
        );
        $stmt->execute([$sessionId, $userId, $team, $actorId]);

        Audit::log($actorId, 'SESSION_ASSIGN_COUNTER', 'stock_opname_sessions', $sessionId, null, ['user_id' => $userId, 'team' => $team]);

        return $this->listCounters($sessionId);
    }

    public function unassignCounter(int $sessionId, int $userId, int $actorId): array
    {
        $session = $this->find($sessionId);
        if (!$session) {
            throw new RuntimeException('Session tidak ditemukan.');
        }
        if ($session['status'] !== 'DRAFT') {
            throw new RuntimeException('Assignment hanya dapat diubah selama session berstatus DRAFT.');
        }
        $this->pdo->prepare("UPDATE stock_opname_session_counters SET status = 'REMOVED' WHERE session_id = ? AND user_id = ?")
            ->execute([$sessionId, $userId]);
        Audit::log($actorId, 'SESSION_UNASSIGN_COUNTER', 'stock_opname_sessions', $sessionId, null, ['user_id' => $userId]);
        return $this->listCounters($sessionId);
    }

    public function listCounters(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sc.*, u.username, u.full_name FROM stock_opname_session_counters sc
             JOIN users u ON u.id = sc.user_id
             WHERE sc.session_id = ? AND sc.status = 'ACTIVE'
             ORDER BY sc.team, u.full_name"
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Start-session preflight (design review point 4)
    // ------------------------------------------------------------------

    /** @return array{blockers: array<int,string>, item_count: int} */
    public function preflight(int $sessionId): array
    {
        $blockers = [];
        $session = $this->find($sessionId);
        if (!$session) {
            return ['blockers' => ['Session tidak ditemukan.'], 'item_count' => 0];
        }

        if ($session['status'] !== 'DRAFT') {
            $blockers[] = "Session harus berstatus DRAFT untuk dimulai (saat ini: {$session['status']}).";
        }

        $locStmt = $this->pdo->prepare('SELECT * FROM locations WHERE id = ? LIMIT 1');
        $locStmt->execute([$session['location_id']]);
        $location = $locStmt->fetch();
        if (!$location) {
            $blockers[] = 'Lokasi tidak ditemukan.';
        } elseif ($location['status'] !== 'ACTIVE') {
            $blockers[] = "Lokasi '{$location['name']}' berstatus INACTIVE.";
        }

        $countersStmt = $this->pdo->prepare(
            "SELECT team, COUNT(*) c FROM stock_opname_session_counters WHERE session_id = ? AND status = 'ACTIVE' GROUP BY team"
        );
        $countersStmt->execute([$sessionId]);
        $counterCounts = array_column($countersStmt->fetchAll(), 'c', 'team');
        if (empty($counterCounts['P1'])) {
            $blockers[] = 'Belum ada petugas P1 yang di-assign.';
        }
        if (empty($counterCounts['P2'])) {
            $blockers[] = 'Belum ada petugas P2 yang di-assign.';
        }

        if (empty($session['system_stock_batch_id'])) {
            $blockers[] = 'Belum ada stock import batch yang committed untuk lokasi ini.';
        } else {
            $batchStmt = $this->pdo->prepare('SELECT status FROM stock_import_batches WHERE id = ? LIMIT 1');
            $batchStmt->execute([$session['system_stock_batch_id']]);
            $batchStatus = $batchStmt->fetchColumn();
            if ($batchStatus !== 'COMMITTED') {
                $blockers[] = "Stock import batch #{$session['system_stock_batch_id']} berstatus {$batchStatus}, harus COMMITTED.";
            }
        }

        $items = $this->resolveScopeItems($session);
        if (count($items) === 0) {
            $blockers[] = 'Scope session ini menghasilkan 0 SKU (tidak ada item ACTIVE yang cocok).';
        } else {
            foreach ($items as $item) {
                $issues = UnitConversion::validateConversion(
                    $item['buy_unit'], $item['buy_content'], $item['mid_unit'], $item['mid_content'], $item['base_unit']
                );
                if (UnitConversion::hasBlockingErrors($issues)) {
                    $blockers[] = "Konversi satuan tidak valid untuk SKU {$item['sku']} ({$item['name']}).";
                }
            }
        }

        $overlapStmt = $this->pdo->prepare(
            "SELECT session_no, scope_type, category_id FROM stock_opname_sessions
             WHERE id != ? AND location_id = ? AND status IN ('ACTIVE','REVIEW')"
        );
        $overlapStmt->execute([$sessionId, $session['location_id']]);
        foreach ($overlapStmt->fetchAll() as $other) {
            $overlaps = $session['scope_type'] === 'ALL' || $other['scope_type'] === 'ALL'
                || ($session['scope_type'] === 'CATEGORY' && $other['scope_type'] === 'CATEGORY' && $other['category_id'] == $session['category_id']);
            if ($overlaps) {
                $blockers[] = "Session {$other['session_no']} pada lokasi yang sama masih {$other['scope_type']}-scope aktif dan tumpang tindih.";
            }
        }

        return ['blockers' => $blockers, 'item_count' => count($items)];
    }

    /** Items in scope: ACTIVE only, matching scope_type/category_id (design decision — see Phase 4 report). */
    private function resolveScopeItems(array $session): array
    {
        if ($session['scope_type'] === 'CATEGORY') {
            $stmt = $this->pdo->prepare("SELECT * FROM items WHERE status = 'ACTIVE' AND category_id = ?");
            $stmt->execute([$session['category_id']]);
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM items WHERE status = 'ACTIVE'");
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Start (atomic snapshot) — design review point 5
    // ------------------------------------------------------------------

    public function startSession(int $sessionId, int $actorId): array
    {
        $this->pdo->beginTransaction();
        try {
            // Lock the session row first so two simultaneous start
            // requests can't both pass the DRAFT check.
            $lockStmt = $this->pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$sessionId]);
            $session = $lockStmt->fetch();
            if (!$session) {
                throw new RuntimeException('Session tidak ditemukan.');
            }
            if ($session['status'] !== 'DRAFT') {
                throw new RuntimeException("Session sudah berstatus {$session['status']}, tidak dapat dimulai ulang.");
            }

            $preflight = $this->preflight($sessionId);
            if (!empty($preflight['blockers'])) {
                throw new SessionPreflightException($preflight['blockers']);
            }

            $items = $this->resolveScopeItems($session);
            $provider = new ImportSystemStockProvider($this->pdo);

            $insertItem = $this->pdo->prepare(
                'INSERT INTO stock_opname_session_items
                    (session_id, item_id, sku_snapshot, barcode_snapshot, name_snapshot, category_snapshot, brand_snapshot,
                     buy_unit_snapshot, buy_content_snapshot, mid_unit_snapshot, mid_content_snapshot, base_unit_snapshot,
                     system_qty_snapshot, unit_cost_snapshot)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $categoryNameStmt = $this->pdo->prepare('SELECT name FROM categories WHERE id = ?');
            $categoryNameCache = [];

            foreach ($items as $item) {
                if (!isset($categoryNameCache[$item['category_id']])) {
                    $categoryNameStmt->execute([$item['category_id']]);
                    $categoryNameCache[$item['category_id']] = $categoryNameStmt->fetchColumn() ?: '';
                }
                $stock = $provider->getSystemStock((int) $item['id'], (int) $session['location_id']);
                // No item_stock row yet for this item at this location:
                // snapshot qty 0 with the item's own last_buy_price as cost,
                // rather than blocking the whole session on one missing row.
                $systemQty = $stock !== null ? $stock->qty : 0.0;
                $unitCost  = $stock !== null ? $stock->unitCost : (float) $item['last_buy_price'];

                $insertItem->execute([
                    $sessionId, $item['id'], $item['sku'], $item['barcode'], $item['name'],
                    $categoryNameCache[$item['category_id']], $item['brand'],
                    $item['buy_unit'], $item['buy_content'], $item['mid_unit'], $item['mid_content'], $item['base_unit'],
                    $systemQty, $unitCost,
                ]);
            }

            $this->pdo->prepare(
                "UPDATE stock_opname_sessions SET status = 'ACTIVE', snapshot_at = NOW(), started_at = NOW(), started_by = ? WHERE id = ?"
            )->execute([$actorId, $sessionId]);

            Audit::log($actorId, 'SESSION_START', 'stock_opname_sessions', $sessionId, ['status' => 'DRAFT'], [
                'status' => 'ACTIVE', 'item_count' => count($items), 'system_stock_batch_id' => $session['system_stock_batch_id'],
            ]);

            $this->pdo->commit();
            return $this->find($sessionId);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
