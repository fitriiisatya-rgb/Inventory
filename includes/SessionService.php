<?php
declare(strict_types=1);

final class SessionPreflightException extends RuntimeException
{
    /** @param array{blockers: array, item_count: int, missing_system_stock: array, cost_warnings: array} $preflight */
    public function __construct(public readonly array $preflight)
    {
        parent::__construct('Session start preflight failed: ' . implode('; ', $preflight['blockers']));
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
    // Counter assignment — DRAFT or ACTIVE (design review points 7-11).
    // A reason is mandatory whenever the session is already ACTIVE; a
    // team change is NEVER a silent UPDATE of the existing row (which
    // would rewrite which team a user's already-recorded counts appear
    // to belong to) — it is always remove-old-row + add-new-row, both
    // individually audited, so history stays exactly reconstructable:
    // who was assigned, who was added, who was removed, when, by whom,
    // and why.
    // ------------------------------------------------------------------

    public function assignCounter(int $sessionId, int $userId, string $team, int $actorId, ?string $reason = null): array
    {
        $session = $this->find($sessionId);
        if (!$session) {
            throw new RuntimeException('Session tidak ditemukan.');
        }
        if (!in_array($session['status'], ['DRAFT', 'ACTIVE'], true)) {
            throw new RuntimeException("Assignment tidak dapat diubah pada session berstatus {$session['status']}.");
        }
        if (!in_array($team, ['P1', 'P2'], true)) {
            throw new InvalidArgumentException("team harus 'P1' atau 'P2'.");
        }
        $reason = $reason !== null ? trim($reason) : null;
        if ($session['status'] === 'ACTIVE' && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('reason wajib diisi untuk mengubah assignment pada session yang sudah ACTIVE.');
        }

        $userStmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user || $user['role'] !== 'COUNTER') {
            throw new InvalidArgumentException('User yang di-assign harus memiliki role COUNTER.');
        }

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare(
                "SELECT * FROM stock_opname_session_counters WHERE session_id = ? AND user_id = ? AND status = 'ACTIVE' LIMIT 1 FOR UPDATE"
            );
            $existingStmt->execute([$sessionId, $userId]);
            $existing = $existingStmt->fetch();

            if ($existing && $existing['team'] === $team) {
                $this->pdo->commit();
                return $this->listCounters($sessionId); // already assigned to this team — idempotent
            }

            if ($existing) {
                // Team change: close out the OLD row under its original team
                // (that team's already-recorded counts keep pointing to it
                // correctly) and release any lock this user holds under it.
                $this->pdo->prepare(
                    "UPDATE stock_opname_session_counters SET status = 'REMOVED', removed_by = ?, removed_at = NOW(), removed_reason = ? WHERE id = ?"
                )->execute([$actorId, $reason ?? 'Dipindahkan ke team lain', $existing['id']]);

                $this->pdo->prepare(
                    "DELETE l FROM stock_opname_item_locks l
                     JOIN stock_opname_session_items si ON si.id = l.session_item_id
                     WHERE l.user_id = ? AND l.team = ? AND si.session_id = ?"
                )->execute([$userId, $existing['team'], $sessionId]);
            }

            $this->pdo->prepare(
                'INSERT INTO stock_opname_session_counters (session_id, user_id, team, status, assigned_by, assigned_reason)
                 VALUES (?, ?, ?, \'ACTIVE\', ?, ?)'
            )->execute([$sessionId, $userId, $team, $actorId, $reason]);

            Audit::log(
                $actorId, 'SESSION_ASSIGN_COUNTER', 'stock_opname_sessions', $sessionId,
                $existing ? ['user_id' => $userId, 'team' => $existing['team']] : null,
                ['user_id' => $userId, 'team' => $team, 'reason' => $reason, 'session_status' => $session['status']]
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->listCounters($sessionId);
    }

    public function unassignCounter(int $sessionId, int $userId, int $actorId, ?string $reason = null): array
    {
        $session = $this->find($sessionId);
        if (!$session) {
            throw new RuntimeException('Session tidak ditemukan.');
        }
        if (!in_array($session['status'], ['DRAFT', 'ACTIVE'], true)) {
            throw new RuntimeException("Assignment tidak dapat diubah pada session berstatus {$session['status']}.");
        }
        $reason = $reason !== null ? trim($reason) : null;
        if ($session['status'] === 'ACTIVE' && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('reason wajib diisi untuk unassign pada session yang sudah ACTIVE.');
        }

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare(
                "SELECT * FROM stock_opname_session_counters WHERE session_id = ? AND user_id = ? AND status = 'ACTIVE' LIMIT 1 FOR UPDATE"
            );
            $existingStmt->execute([$sessionId, $userId]);
            $existing = $existingStmt->fetch();
            if (!$existing) {
                throw new RuntimeException('User ini tidak sedang aktif di-assign pada session ini.');
            }

            $this->pdo->prepare(
                "UPDATE stock_opname_session_counters SET status = 'REMOVED', removed_by = ?, removed_at = NOW(), removed_reason = ? WHERE id = ?"
            )->execute([$actorId, $reason, $existing['id']]);

            // Release any lock this user holds on this session's items — but
            // their already-saved counts, revisions, and this assignment row
            // itself are never touched, so history stays fully reconstructable.
            $this->pdo->prepare(
                "DELETE l FROM stock_opname_item_locks l
                 JOIN stock_opname_session_items si ON si.id = l.session_item_id
                 WHERE l.user_id = ? AND si.session_id = ?"
            )->execute([$userId, $sessionId]);

            Audit::log(
                $actorId, 'SESSION_UNASSIGN_COUNTER', 'stock_opname_sessions', $sessionId,
                ['user_id' => $userId, 'team' => $existing['team']],
                ['reason' => $reason, 'session_status' => $session['status']]
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

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

    /** Full reconstructable history: every assignment and removal, by whom, when, why. */
    public function listCounterHistory(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sc.*, u.full_name, ab.full_name AS assigned_by_name, rb.full_name AS removed_by_name
             FROM stock_opname_session_counters sc
             JOIN users u ON u.id = sc.user_id
             JOIN users ab ON ab.id = sc.assigned_by
             LEFT JOIN users rb ON rb.id = sc.removed_by
             WHERE sc.session_id = ?
             ORDER BY sc.assigned_at'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // Start-session preflight (design review point 4)
    // ------------------------------------------------------------------

    /**
     * @return array{blockers: array<int,string>, item_count: int,
     *               missing_system_stock: array<int,array>, cost_warnings: array<int,array>}
     */
    public function preflight(int $sessionId): array
    {
        $blockers = [];
        $missingSystemStock = [];
        $costWarnings = [];
        $session = $this->find($sessionId);
        if (!$session) {
            return ['blockers' => ['Session tidak ditemukan.'], 'item_count' => 0, 'missing_system_stock' => [], 'cost_warnings' => []];
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

            // MISSING SYSTEM STOCK != ZERO STOCK (design review, correction 1-4).
            // Every NORMAL in-scope item must have a committed row in the
            // SPECIFIC batch this session is bound to — not just "some value
            // exists in the rolling item_stock table" — or session start is
            // refused outright. There is no "treat missing as 0" shortcut.
            if (!empty($session['system_stock_batch_id'])) {
                $categoryNameStmt = $this->pdo->prepare('SELECT name FROM categories WHERE id = ?');
                $rowStmt = $this->pdo->prepare(
                    "SELECT parsed_unit_cost, unit_cost_source FROM stock_import_rows
                     WHERE batch_id = ? AND item_id = ? AND status IN ('MATCHED','WARNING') AND committed = 1 LIMIT 1"
                );
                foreach ($items as $item) {
                    $rowStmt->execute([$session['system_stock_batch_id'], $item['id']]);
                    $row = $rowStmt->fetch();
                    $categoryNameStmt->execute([$item['category_id']]);
                    $categoryName = $categoryNameStmt->fetchColumn() ?: '';

                    if (!$row) {
                        $missingSystemStock[] = [
                            'sku' => $item['sku'], 'name' => $item['name'], 'category' => $categoryName,
                            'location_id' => (int) $session['location_id'], 'reason' => 'MISSING_SYSTEM_STOCK',
                        ];
                        continue;
                    }
                    if ($row['parsed_unit_cost'] === null) {
                        $costWarnings[] = ['sku' => $item['sku'], 'name' => $item['name'], 'category' => $categoryName];
                    }
                }
                if (!empty($missingSystemStock)) {
                    $blockers[] = count($missingSystemStock) . ' SKU tidak memiliki System Stock pada import batch ini.';
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

        return [
            'blockers' => $blockers,
            'item_count' => count($items),
            'missing_system_stock' => $missingSystemStock,
            'cost_warnings' => $costWarnings,
        ];
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
                throw new SessionPreflightException($preflight);
            }

            $items = $this->resolveScopeItems($session);
            // Bound to THIS session's specific committed batch (design review
            // point 3) — never the generically "current" item_stock table.
            $provider = new ImportSystemStockProvider($this->pdo, (int) $session['system_stock_batch_id']);

            $insertItem = $this->pdo->prepare(
                'INSERT INTO stock_opname_session_items
                    (session_id, item_id, sku_snapshot, barcode_snapshot, name_snapshot, category_snapshot, brand_snapshot,
                     buy_unit_snapshot, buy_content_snapshot, mid_unit_snapshot, mid_content_snapshot, base_unit_snapshot,
                     system_qty_snapshot, unit_cost_snapshot, unit_cost_source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $categoryNameStmt = $this->pdo->prepare('SELECT name FROM categories WHERE id = ?');
            $categoryNameCache = [];

            foreach ($items as $item) {
                if (!isset($categoryNameCache[$item['category_id']])) {
                    $categoryNameStmt->execute([$item['category_id']]);
                    $categoryNameCache[$item['category_id']] = $categoryNameStmt->fetchColumn() ?: '';
                }
                $stock = $provider->getSystemStock((int) $item['id'], (int) $session['location_id']);
                if ($stock === null) {
                    // Preflight already guarantees every NORMAL in-scope item
                    // has a committed row in this exact batch — reaching here
                    // means that invariant broke between preflight and this
                    // transaction. Fail loudly and roll back everything;
                    // NEVER silently default system_qty to 0 (design review
                    // corrections 1-4 exist specifically to prevent that).
                    throw new RuntimeException(
                        "Integrity error: SKU {$item['sku']} has no committed system stock row in batch #{$session['system_stock_batch_id']} despite passing preflight."
                    );
                }

                $insertItem->execute([
                    $sessionId, $item['id'], $item['sku'], $item['barcode'], $item['name'],
                    $categoryNameCache[$item['category_id']], $item['brand'],
                    $item['buy_unit'], $item['buy_content'], $item['mid_unit'], $item['mid_content'], $item['base_unit'],
                    $stock->qty, $stock->unitCost, $stock->costSource,
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
