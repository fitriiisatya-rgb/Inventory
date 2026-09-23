<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE C2 Section 2 (legacy single-count) + PHASE V2.12 (dual-count P1/P2
 * blind counting). Two counting modes share one session/line schema:
 *
 * LEGACY (unchanged since before V2.12, still fully supported): start ->
 * count() [one operator, immediate] -> finalize -> post. A session never
 * gets p1_user_id/p2_user_id assigned, so it can never accidentally enter
 * the dual-count path — count() itself refuses to run on a session that
 * HAS been assigned P1/P2, and the P1/P2 endpoints refuse to run on a
 * session that has none assigned. The two modes cannot mix on one session.
 *
 * DUAL-COUNT (V2.12): start(scope) -> assignCounters(p1, p2) -> submitCount
 * ('p1'|'p2', blind — see getForCounter()) -> the moment both P1 and P2
 * have a value for a line, resolveMatchStatus() runs automatically (the
 * mission's "SYSTEM COMPARE" step is simply this, not a separate manual
 * action) -> MATCH lines resolve immediately; MISMATCH lines require
 * recount() (supervisor-only) -> a supervisor may excludeUncounted() a
 * still-PENDING or still-MISMATCH line with a reason -> finalize() (now
 * gated on "every line is MATCH/RECOUNTED/EXCLUDED", still keyed off the
 * exact same `is_counted`/`variance_qty_base` columns the legacy path
 * always used) -> post() (byte-for-byte unchanged — it has never cared
 * HOW counted_qty_base/variance_qty_base were resolved).
 *
 * Nothing here ever overwrites a batch's qty_base directly — posting
 * always goes through StockAdjustmentService, the only thing allowed to
 * touch batches for a correction. No new session-level status was added
 * for the P1/COUNTING/COMPARISON/RECOUNT/READY_FOR_REVIEW continuum the
 * mission describes — OPEN already covers all of it end-to-end, and the
 * per-line `match_status` (PENDING/MATCH/MISMATCH/RECOUNTED/EXCLUDED)
 * carries the granularity a UI needs (progress bars, counts) without a
 * combinatorial session-status state machine. finalize()/POSTED/CANCELLED
 * are unchanged from the original C2 model.
 */
final class StockOpnameService
{
    private const QTY_EPSILON = 0.0000005; // half the smallest representable unit at DECIMAL(20,6)

    public static function start(PDO $pdo, int $warehouseId, int $createdBy, ?array $itemIds = null): int
    {
        if (WarehouseLockService::isLocked($pdo, $warehouseId)) {
            throw new ValidationException(["warehouse {$warehouseId} already has an active opname session"]);
        }

        $now = date('Y-m-d H:i:s');
        $uuid = self::uuid();
        $scope = $itemIds === null ? 'ALL_ACTIVE_STOCK' : 'SELECTED_ITEMS';
        $sessionNumber = NumberingService::next($pdo, 'SO', $now);
        $stmt = $pdo->prepare(
            'INSERT INTO stock_opname_sessions (warehouse_id, session_date, session_uuid, session_number, scope, status, created_by, created_at)
             VALUES (:wh, :date, :uuid, :session_number, :scope, \'OPEN\', :created_by, :now)'
        );
        $stmt->execute([
            'wh' => $warehouseId, 'date' => substr($now, 0, 10), 'uuid' => $uuid,
            'session_number' => $sessionNumber, 'scope' => $scope, 'created_by' => $createdBy, 'now' => $now,
        ]);
        $sessionId = (int) $pdo->lastInsertId();

        if ($itemIds === null) {
            $scan = $pdo->prepare('SELECT DISTINCT item_id FROM inventory_batches WHERE warehouse_id = :wh AND qty_base <> 0');
            $scan->execute(['wh' => $warehouseId]);
            $itemIds = array_map('intval', array_column($scan->fetchAll(), 'item_id'));
        }

        $lineStmt = $pdo->prepare(
            'INSERT INTO stock_opname_lines (session_id, item_id, system_qty_base, counted_qty_base, is_counted, unit_cost_base)
             VALUES (:session_id, :item_id, :system_qty, NULL, 0, :cost)'
        );
        foreach ($itemIds as $itemId) {
            $stock = InventoryService::currentStock($pdo, (int) $itemId, $warehouseId);
            $cost = $stock['qty_base'] != 0 ? round($stock['value'] / $stock['qty_base'], 4) : 0.0;
            $lineStmt->execute(['session_id' => $sessionId, 'item_id' => $itemId, 'system_qty' => $stock['qty_base'], 'cost' => $cost]);
        }

        AuditService::log($pdo, $createdBy, 'system', 'STOCK_OPNAME_START', 'stock_opname_sessions', $sessionId, null, ['warehouse_id' => $warehouseId, 'item_count' => count($itemIds), 'scope' => $scope, 'session_number' => $sessionNumber], null);

        return $sessionId;
    }

    public static function get(PDO $pdo, int $sessionId): array
    {
        $session = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $session->execute(['id' => $sessionId]);
        $session = $session->fetch();
        if (!$session) {
            throw new NotFoundException('opname session not found');
        }
        $lines = $pdo->prepare(
            'SELECT sol.*, i.sku, i.name FROM stock_opname_lines sol JOIN items i ON i.id = sol.item_id WHERE session_id = :id ORDER BY i.name'
        );
        $lines->execute(['id' => $sessionId]);
        $session['lines'] = $lines->fetchAll();
        return $session;
    }

    /**
     * PHASE V2.12A — blind view for the assigned P1 or P2 counter (Section
     * 4). Never exposes the OTHER counter's qty/user/timestamp, never
     * exposes system_qty_base, never exposes match_status/recount detail —
     * only this caller's own prior submission (for resuming) and whether
     * each line is still open to count.
     *
     * @param string $role 'p1'|'p2'
     */
    public static function getForCounter(PDO $pdo, int $sessionId, string $role): array
    {
        if (!in_array($role, ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        $session = $pdo->prepare('SELECT id, warehouse_id, session_date, session_number, scope, status, p1_user_id, p2_user_id FROM stock_opname_sessions WHERE id = :id');
        $session->execute(['id' => $sessionId]);
        $session = $session->fetch();
        if (!$session) {
            throw new NotFoundException('opname session not found');
        }

        $myQtyCol = "{$role}_qty_base";
        $mySubmittedCol = "{$role}_submitted_at";
        $lines = $pdo->prepare(
            "SELECT sol.id, sol.item_id, i.sku, i.name, sol.{$myQtyCol} AS my_qty_base, sol.{$mySubmittedCol} AS my_submitted_at, sol.is_excluded
             FROM stock_opname_lines sol JOIN items i ON i.id = sol.item_id
             WHERE sol.session_id = :id ORDER BY i.name"
        );
        $lines->execute(['id' => $sessionId]);
        $rows = $lines->fetchAll();

        $formatted = array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'item_id' => (int) $r['item_id'],
                'sku' => $r['sku'],
                'name' => $r['name'],
                'my_qty_base' => $r['my_qty_base'],
                'my_submitted_at' => $r['my_submitted_at'],
                'is_counted_by_me' => $r['my_qty_base'] !== null,
                'is_excluded' => (bool) $r['is_excluded'],
            ];
        }, $rows);

        $counted = count(array_filter($formatted, static fn ($l) => $l['is_counted_by_me'] || $l['is_excluded']));

        return [
            'session_id' => (int) $session['id'],
            'session_number' => $session['session_number'],
            'session_date' => $session['session_date'],
            'scope' => $session['scope'],
            'status' => $session['status'],
            'role' => $role,
            'progress' => ['counted' => $counted, 'total' => count($formatted)],
            'lines' => $formatted,
        ];
    }

    /**
     * PHASE V2.12A — designate the two independent blind counters. Backend-
     * enforced distinct-user rule (Section 3): checked here AND by the DB
     * CHECK constraint. Reassigning a role once that person has already
     * submitted at least one count for this session is refused — that
     * would silently orphan their blind entries under a new identity.
     *
     * @param array{p1_user_id?:int, p2_user_id?:int} $assignments only the
     *   keys present are changed; omit a key to leave that role untouched.
     */
    public static function assignCounters(PDO $pdo, int $sessionId, array $assignments, int $assignedBy): array
    {
        $session = self::requireStatus($pdo, $sessionId, 'OPEN');

        $p1 = array_key_exists('p1_user_id', $assignments) ? (int) $assignments['p1_user_id'] : null;
        $p2 = array_key_exists('p2_user_id', $assignments) ? (int) $assignments['p2_user_id'] : null;

        $resultingP1 = array_key_exists('p1_user_id', $assignments) ? $p1 : ($session['p1_user_id'] !== null ? (int) $session['p1_user_id'] : null);
        $resultingP2 = array_key_exists('p2_user_id', $assignments) ? $p2 : ($session['p2_user_id'] !== null ? (int) $session['p2_user_id'] : null);
        if ($resultingP1 !== null && $resultingP2 !== null && $resultingP1 === $resultingP2) {
            throw new ValidationException(['P1 and P2 must be different users']);
        }

        if (array_key_exists('p1_user_id', $assignments)) {
            self::assertCanCount($pdo, $p1, (int) $session['warehouse_id']);
            self::assertRoleNotYetSubmitted($pdo, $sessionId, 'p1');
        }
        if (array_key_exists('p2_user_id', $assignments)) {
            self::assertCanCount($pdo, $p2, (int) $session['warehouse_id']);
            self::assertRoleNotYetSubmitted($pdo, $sessionId, 'p2');
        }

        $sets = [];
        $params = ['id' => $sessionId];
        if (array_key_exists('p1_user_id', $assignments)) { $sets[] = 'p1_user_id = :p1'; $params['p1'] = $p1; }
        if (array_key_exists('p2_user_id', $assignments)) { $sets[] = 'p2_user_id = :p2'; $params['p2'] = $p2; }
        if ($sets === []) {
            return self::get($pdo, $sessionId);
        }
        $pdo->prepare('UPDATE stock_opname_sessions SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

        AuditService::log($pdo, $assignedBy, 'system', 'STOCK_OPNAME_ASSIGN_COUNTERS', 'stock_opname_sessions', $sessionId, null, $assignments, null);

        return self::get($pdo, $sessionId);
    }

    private static function assertCanCount(PDO $pdo, ?int $userId, int $warehouseId): void
    {
        if ($userId === null) {
            return; // clearing an assignment
        }
        $stmt = $pdo->prepare('SELECT u.id, u.is_active, u.warehouse_id, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user || (int) $user['is_active'] !== 1) {
            throw new ValidationException(["user {$userId} does not exist or is not active"]);
        }
        if (!AuthService::hasPermission($pdo, $user['role_code'], 'STOCK_OPNAME_MANAGE')) {
            throw new ValidationException(["user {$userId} is not authorized to count stock opname (missing STOCK_OPNAME_MANAGE)"]);
        }
        if ($user['warehouse_id'] !== null && (int) $user['warehouse_id'] !== $warehouseId) {
            throw new ValidationException(["user {$userId} is scoped to a different warehouse"]);
        }
    }

    private static function assertRoleNotYetSubmitted(PDO $pdo, int $sessionId, string $role): void
    {
        $col = "{$role}_submitted_at";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = :id AND {$col} IS NOT NULL");
        $stmt->execute(['id' => $sessionId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new ValidationException(["{$role} has already submitted counts for this session — reassigning would orphan those blind entries"]);
        }
    }

    /**
     * PHASE V2.12A — blind, one-item-at-a-time submission for the assigned
     * P1 or P2 counter (Section 6/16 — barcode-scan friendly). Write-once:
     * a genuinely different resubmission is refused (Section 6, "do not
     * overwrite"); an identical resubmission is a no-op (Section 24,
     * idempotent double-submit protection).
     *
     * @param string $role 'p1'|'p2'
     */
    public static function submitCount(PDO $pdo, int $sessionId, string $role, int $itemId, float $qtyBase, int $userId): array
    {
        if (!in_array($role, ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        if ($qtyBase < 0) {
            throw new ValidationException(['counted quantity cannot be negative']);
        }
        $session = self::requireStatus($pdo, $sessionId, 'OPEN');

        $assignedCol = "{$role}_user_id";
        if ($session[$assignedCol] === null) {
            throw new ValidationException(["no {$role} counter has been assigned for this session yet"]);
        }
        if ((int) $session[$assignedCol] !== $userId) {
            throw new ValidationException(["you are not the assigned {$role} counter for this session"]);
        }

        $line = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item');
        $line->execute(['sid' => $sessionId, 'item' => $itemId]);
        $line = $line->fetch();
        if (!$line) {
            throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
        }
        if ((int) $line['is_excluded'] === 1) {
            throw new ValidationException(['this item has already been excluded from the session by a supervisor']);
        }

        $qtyCol = "{$role}_qty_base";
        $userCol = "{$role}_user_id";
        $tsCol = "{$role}_submitted_at";

        if ($line[$qtyCol] !== null) {
            if (self::qtyEquals((float) $line[$qtyCol], $qtyBase)) {
                return self::get($pdo, $sessionId); // idempotent replay of the exact same value
            }
            throw new ValidationException(["{$role} has already counted this item ({$line[$qtyCol]}) — the blind count is immutable once submitted"]);
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE stock_opname_lines SET {$qtyCol} = :qty, {$userCol} = :user, {$tsCol} = :now WHERE id = :id")
            ->execute(['qty' => $qtyBase, 'user' => $userId, 'now' => $now, 'id' => $line['id']]);

        self::resolveMatchStatus($pdo, (int) $line['id']);

        AuditService::log($pdo, $userId, 'system', $role === 'p1' ? 'STOCK_OPNAME_P1_COUNT' : 'STOCK_OPNAME_P2_COUNT', 'stock_opname_lines', (int) $line['id'], null, ['item_id' => $itemId, 'qty_base' => $qtyBase], null);

        return self::get($pdo, $sessionId);
    }

    /**
     * PHASE V2.12A — the mission's "SYSTEM COMPARE" step: runs
     * automatically the instant a line has both a P1 and a P2 value (or a
     * recount). Never averages, never silently picks one side (Section
     * 7/8) — MATCH only when the two independent counts genuinely agree.
     */
    private static function resolveMatchStatus(PDO $pdo, int $lineId): void
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE id = :id');
        $stmt->execute(['id' => $lineId]);
        $line = $stmt->fetch();
        if (!$line || (int) $line['is_excluded'] === 1) {
            return;
        }

        if ($line['recount_qty_base'] !== null) {
            $status = 'RECOUNTED';
            $countedQty = (float) $line['recount_qty_base'];
            $isCounted = 1;
        } elseif ($line['p1_qty_base'] === null || $line['p2_qty_base'] === null) {
            $status = 'PENDING';
            $countedQty = null;
            $isCounted = 0;
        } elseif (self::qtyEquals((float) $line['p1_qty_base'], (float) $line['p2_qty_base'])) {
            $status = 'MATCH';
            $countedQty = (float) $line['p1_qty_base'];
            $isCounted = 1;
        } else {
            $status = 'MISMATCH';
            $countedQty = null;
            $isCounted = 0;
        }

        $pdo->prepare('UPDATE stock_opname_lines SET match_status = :status, counted_qty_base = :counted, is_counted = :is_counted WHERE id = :id')
            ->execute(['status' => $status, 'counted' => $countedQty, 'is_counted' => $isCounted, 'id' => $lineId]);
    }

    private static function qtyEquals(float $a, float $b): bool
    {
        return abs($a - $b) < self::QTY_EPSILON;
    }

    /** @param array<int, float> $counts item_id => counted_qty_base */
    public static function count(PDO $pdo, int $sessionId, array $counts, int $userId): void
    {
        $session = self::requireStatus($pdo, $sessionId, 'OPEN');
        if ($session['p1_user_id'] !== null || $session['p2_user_id'] !== null) {
            throw new ValidationException(['this session uses dual-count blind counting — use the P1/P2 count endpoints instead']);
        }

        $upsert = $pdo->prepare(
            'UPDATE stock_opname_lines SET counted_qty_base = :qty, is_counted = 1 WHERE session_id = :session_id AND item_id = :item_id'
        );
        $insertIfMissing = $pdo->prepare(
            'INSERT INTO stock_opname_lines (session_id, item_id, system_qty_base, counted_qty_base, is_counted, unit_cost_base)
             SELECT :session_id, :item_id, 0, :qty, 1, 0
             WHERE NOT EXISTS (SELECT 1 FROM stock_opname_lines WHERE session_id = :session_id2 AND item_id = :item_id2)'
        );

        foreach ($counts as $itemId => $qty) {
            $upsert->execute(['qty' => $qty, 'session_id' => $sessionId, 'item_id' => $itemId]);
            $insertIfMissing->execute(['session_id' => $sessionId, 'item_id' => $itemId, 'qty' => $qty, 'session_id2' => $sessionId, 'item_id2' => $itemId]);
        }

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_COUNT', 'stock_opname_sessions', $sessionId, null, ['counted_items' => count($counts)], null);
    }

    /**
     * PHASE V2.12B — supervisor-only comparison/review dashboard (Section
     * 11/18). Read-only aggregate over the same lines finalize() will use;
     * never mutates anything.
     */
    public static function review(PDO $pdo, int $sessionId): array
    {
        $session = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $session->execute(['id' => $sessionId]);
        $session = $session->fetch();
        if (!$session) {
            throw new NotFoundException('opname session not found');
        }

        $lines = $pdo->prepare(
            'SELECT sol.*, i.sku, i.name, u.code AS unit_code
             FROM stock_opname_lines sol
             JOIN items i ON i.id = sol.item_id
             LEFT JOIN units u ON u.id = i.base_unit_id
             WHERE sol.session_id = :id ORDER BY i.name'
        );
        $lines->execute(['id' => $sessionId]);
        $rows = $lines->fetchAll();

        $summary = [
            'total_items' => count($rows), 'match' => 0, 'mismatch' => 0, 'recounted' => 0,
            'not_counted' => 0, 'excluded' => 0,
            'positive_difference_count' => 0, 'negative_difference_count' => 0,
            'estimated_adjustment_value' => 0.0,
        ];
        foreach ($rows as $r) {
            switch ($r['match_status']) {
                case 'MATCH': $summary['match']++; break;
                case 'MISMATCH': $summary['mismatch']++; break;
                case 'RECOUNTED': $summary['recounted']++; break;
                case 'EXCLUDED': $summary['excluded']++; break;
                default: $summary['not_counted']++; break;
            }
            if ($r['counted_qty_base'] !== null) {
                $diff = round((float) $r['counted_qty_base'] - (float) $r['system_qty_base'], 6);
                if ($diff > 0) { $summary['positive_difference_count']++; }
                if ($diff < 0) { $summary['negative_difference_count']++; }
                $summary['estimated_adjustment_value'] += $diff * (float) $r['unit_cost_base'];
            }
        }
        $summary['estimated_adjustment_value'] = round($summary['estimated_adjustment_value'], 4);

        return [
            'session' => $session,
            'summary' => $summary,
            'lines' => array_map(static function (array $r): array {
                $diff = $r['counted_qty_base'] !== null ? round((float) $r['counted_qty_base'] - (float) $r['system_qty_base'], 6) : null;
                return [
                    'id' => (int) $r['id'],
                    'item_id' => (int) $r['item_id'],
                    'sku' => $r['sku'],
                    'name' => $r['name'],
                    'unit_code' => $r['unit_code'],
                    'system_qty_base' => (float) $r['system_qty_base'],
                    'p1_qty_base' => $r['p1_qty_base'],
                    'p2_qty_base' => $r['p2_qty_base'],
                    'match_status' => $r['match_status'],
                    'recount_qty_base' => $r['recount_qty_base'],
                    'recount_reason' => $r['recount_reason'],
                    'final_physical_qty_base' => $r['counted_qty_base'],
                    'difference_qty_base' => $diff,
                    'difference_value' => $diff !== null ? round($diff * (float) $r['unit_cost_base'], 4) : null,
                    'is_excluded' => (bool) $r['is_excluded'],
                    'notes' => $r['notes'],
                ];
            }, $rows),
        ];
    }

    /**
     * PHASE V2.12B — recount for a MISMATCH line (Section 9). Preserves
     * the original P1/P2 values untouched; only ever resolves the FINAL
     * physical quantity via counted_qty_base (through resolveMatchStatus).
     */
    public static function recount(PDO $pdo, int $sessionId, int $itemId, float $qtyBase, string $reason, int $userId): array
    {
        if (trim($reason) === '') {
            throw new ValidationException(['a recount reason is required']);
        }
        if ($qtyBase < 0) {
            throw new ValidationException(['recount quantity cannot be negative']);
        }
        self::requireStatus($pdo, $sessionId, 'OPEN');

        $line = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item');
        $line->execute(['sid' => $sessionId, 'item' => $itemId]);
        $line = $line->fetch();
        if (!$line) {
            throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
        }

        if ($line['recount_qty_base'] !== null) {
            if (self::qtyEquals((float) $line['recount_qty_base'], $qtyBase)) {
                return self::get($pdo, $sessionId); // idempotent replay
            }
            throw new ValidationException(['this item has already been recounted — the recount is immutable once submitted']);
        }
        if ($line['match_status'] !== 'MISMATCH') {
            throw new ValidationException(["only a MISMATCH item can be recounted (current status: {$line['match_status']})"]);
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE stock_opname_lines SET recount_qty_base = :qty, recount_user_id = :user, recount_submitted_at = :now, recount_reason = :reason WHERE id = :id')
            ->execute(['qty' => $qtyBase, 'user' => $userId, 'now' => $now, 'reason' => $reason, 'id' => $line['id']]);

        self::resolveMatchStatus($pdo, (int) $line['id']);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_RECOUNT', 'stock_opname_lines', (int) $line['id'], null, ['item_id' => $itemId, 'qty_base' => $qtyBase, 'reason' => $reason], null);

        return self::get($pdo, $sessionId);
    }

    /**
     * PHASE V2.12B — supervisor excuses a still-unresolved item (PENDING
     * or MISMATCH) from blocking finalize (Section 10). Never applies to a
     * MATCH/RECOUNTED line — those are already resolved and don't need
     * excusing.
     */
    public static function excludeUncounted(PDO $pdo, int $sessionId, int $itemId, string $reason, int $userId): array
    {
        if (trim($reason) === '') {
            throw new ValidationException(['an exclusion reason is required']);
        }
        self::requireStatus($pdo, $sessionId, 'OPEN');

        $line = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item');
        $line->execute(['sid' => $sessionId, 'item' => $itemId]);
        $line = $line->fetch();
        if (!$line) {
            throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
        }
        if (!in_array($line['match_status'], ['PENDING', 'MISMATCH'], true)) {
            throw new ValidationException(["only a PENDING or MISMATCH item can be excluded (current status: {$line['match_status']})"]);
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE stock_opname_lines SET is_excluded = 1, match_status = \'EXCLUDED\', notes = :reason, excluded_by = :user, excluded_at = :now WHERE id = :id')
            ->execute(['reason' => $reason, 'user' => $userId, 'now' => $now, 'id' => $line['id']]);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_EXCLUDE', 'stock_opname_lines', (int) $line['id'], null, ['item_id' => $itemId, 'reason' => $reason], null);

        return self::get($pdo, $sessionId);
    }

    public static function finalize(PDO $pdo, int $sessionId, int $userId): array
    {
        $session = self::requireStatus($pdo, $sessionId, 'OPEN');

        // PHASE V2.12B: an EXCLUDED line is deliberately not required to be
        // counted (Section 10) — everything else (legacy single-count
        // sessions included, where is_excluded is always 0) behaves
        // identically to the original C2 check.
        $uncountedStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = :id AND is_counted = 0 AND is_excluded = 0');
        $uncountedStmt->execute(['id' => $sessionId]);
        $uncountedCount = (int) $uncountedStmt->fetchColumn();
        if ($uncountedCount > 0) {
            throw new ValidationException(["{$uncountedCount} item(s) have not been counted yet — finalize requires every line counted, recounted, or explicitly excluded"]);
        }

        $lines = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :id');
        $lines->execute(['id' => $sessionId]);
        $lines = $lines->fetchAll();

        $updateVariance = $pdo->prepare(
            'UPDATE stock_opname_lines SET variance_qty_base = :variance, cost_required = :cost_required WHERE id = :id'
        );
        foreach ($lines as $line) {
            if ((int) $line['is_excluded'] === 1) {
                // Never computes a phantom variance for an item nobody
                // actually counted — system qty stands, no adjustment.
                continue;
            }
            $variance = round((float) $line['counted_qty_base'] - (float) $line['system_qty_base'], 6);
            $costRequired = 0;
            if ($variance > 0) {
                $lastCost = $pdo->prepare('SELECT unit_cost_base FROM inventory_batches WHERE item_id = :item_id ORDER BY received_date DESC, id DESC LIMIT 1');
                $lastCost->execute(['item_id' => $line['item_id']]);
                $cost = $lastCost->fetchColumn();
                $costRequired = ($cost === false || (float) $cost <= 0) ? 1 : 0;
            }
            $updateVariance->execute(['variance' => $variance, 'cost_required' => $costRequired, 'id' => $line['id']]);
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE stock_opname_sessions SET status = \'FINALIZED\', supervisor_id = COALESCE(supervisor_id, :by1), finalized_by = :by2, finalized_at = :now WHERE id = :id')
            ->execute(['by1' => $userId, 'by2' => $userId, 'now' => $now, 'id' => $sessionId]);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_FINALIZE', 'stock_opname_sessions', $sessionId, null, null, null);

        return self::get($pdo, $sessionId);
    }

    /** @param array<int, float> $costOverrides item_id => override unit cost, for lines flagged cost_required */
    public static function post(PDO $pdo, int $sessionId, int $userId, array $costOverrides = []): array
    {
        $session = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $session->execute(['id' => $sessionId]);
        $session = $session->fetch();
        if (!$session) {
            throw new NotFoundException('opname session not found');
        }

        if ($session['status'] === 'POSTED') {
            // Idempotent: already posted, return the adjustments already created rather than reposting.
            $existing = $pdo->prepare('SELECT id, item_id, adjustment_id FROM stock_opname_lines WHERE session_id = :id AND adjustment_id IS NOT NULL');
            $existing->execute(['id' => $sessionId]);
            return ['session_id' => $sessionId, 'status' => 'POSTED', 'idempotent_replay' => true, 'adjustments' => $existing->fetchAll()];
        }

        if ($session['status'] !== 'FINALIZED') {
            throw new ValidationException(['opname session must be FINALIZED before it can be posted']);
        }

        $lines = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :id AND variance_qty_base <> 0');
        $lines->execute(['id' => $sessionId]);
        $lines = $lines->fetchAll();

        foreach ($lines as $line) {
            if ((int) $line['cost_required'] === 1 && !isset($costOverrides[$line['item_id']])) {
                throw new CostRequiredException((int) $line['item_id']);
            }
        }

        $adjustments = [];
        foreach ($lines as $line) {
            $override = $costOverrides[$line['item_id']] ?? null;
            $result = StockAdjustmentService::post($pdo, [
                'transaction_uuid' => $session['session_uuid'] . ':' . $line['item_id'],
                'item_id' => (int) $line['item_id'],
                'warehouse_id' => (int) $session['warehouse_id'],
                'qty_base_delta' => (float) $line['variance_qty_base'],
                'adjustment_type' => 'OPNAME',
                'reason' => "Stock opname session #{$sessionId}",
                'reference_no' => $session['session_number'] ?? "OPNAME-{$sessionId}",
                'transaction_date' => $session['session_date'] . ' 23:59:59',
                'created_by' => $userId,
                'override_cost_base' => $override,
                'bypass_warehouse_lock' => true,
            ]);

            $pdo->prepare('UPDATE stock_opname_lines SET adjustment_id = :adj_id WHERE id = :id')
                ->execute(['adj_id' => $result['adjustment_id'], 'id' => $line['id']]);
            $adjustments[] = $result;
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE stock_opname_sessions SET status = \'POSTED\', posted_by = :by, posted_at = :now WHERE id = :id')
            ->execute(['by' => $userId, 'now' => $now, 'id' => $sessionId]);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_POST', 'stock_opname_sessions', $sessionId, null, ['adjustments' => count($adjustments)], null);

        return ['session_id' => $sessionId, 'status' => 'POSTED', 'adjustments' => $adjustments];
    }

    /**
     * PHASE V2.12B — before POST: authorized cancellation allowed (Section
     * 23). After POST, a session can never be cancelled here — a
     * controlled correction/VOID process is required instead (this method
     * intentionally has no code path back from POSTED).
     */
    public static function cancel(PDO $pdo, int $sessionId, string $reason, int $userId): array
    {
        if (trim($reason) === '') {
            throw new ValidationException(['a cancellation reason is required']);
        }
        $session = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $session->execute(['id' => $sessionId]);
        $session = $session->fetch();
        if (!$session) {
            throw new NotFoundException('opname session not found');
        }
        if ($session['status'] === 'CANCELLED') {
            return self::get($pdo, $sessionId); // idempotent replay
        }
        if ($session['status'] === 'POSTED') {
            throw new ValidationException(['a POSTED opname session cannot be cancelled — use a controlled correction/VOID process instead']);
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE stock_opname_sessions SET status = \'CANCELLED\', cancelled_by = :by, cancelled_at = :now WHERE id = :id')
            ->execute(['by' => $userId, 'now' => $now, 'id' => $sessionId]);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_CANCEL', 'stock_opname_sessions', $sessionId, null, ['reason' => $reason], $reason);

        return self::get($pdo, $sessionId);
    }

    private static function requireStatus(PDO $pdo, int $sessionId, string $expected): array
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        $session = $stmt->fetch();
        if (!$session) {
            throw new NotFoundException('opname session not found');
        }
        if ($session['status'] !== $expected) {
            throw new ValidationException(["opname session must be {$expected}, currently {$session['status']}"]);
        }
        return $session;
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
