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
    // PHASE V2.14.10 — an item claim (see claimItem()) auto-expires after
    // this many seconds of inactivity, so an abandoned claim never
    // permanently blocks a teammate from picking up that SKU.
    private const CLAIM_LEASE_SECONDS = 900;

    /**
     * PHASE V2.14.10.1 Gate 2 — $countingModel is explicit, never inferred:
     * every caller of start() states up front which write-model this
     * session will use. Defaults to FINDINGS_V1 (the going-forward model
     * for any new session created through the real app's "Mulai Opname"
     * action) — a caller that genuinely needs the legacy write-once P1/P2
     * model on a BRAND NEW session (e.g. an integration not yet migrated,
     * or a test exercising that model specifically) passes
     * 'LEGACY_DUAL_COUNT' explicitly. A pre-existing session (created
     * before this phase) is never touched by this parameter at all — it
     * keeps LEGACY_DUAL_COUNT via the column's own DEFAULT — and the only
     * way one of those moves to FINDINGS_V1 is the explicit, one-way
     * upgradeToFindingsMode() (only while it still has zero submitted
     * counts).
     */
    public static function start(PDO $pdo, int $warehouseId, int $createdBy, ?array $itemIds = null, string $countingModel = 'FINDINGS_V1'): int
    {
        if (!in_array($countingModel, ['LEGACY_DUAL_COUNT', 'FINDINGS_V1'], true)) {
            throw new ValidationException(['counting_model must be LEGACY_DUAL_COUNT or FINDINGS_V1']);
        }

        // PHASE V2.13: start() never posts a FIFO transaction itself, so it
        // is not covered by FifoService's own guard — an inactive warehouse
        // must not be allowed to begin a physical-count workflow either.
        WarehouseGuardService::assertActive($pdo, $warehouseId);
        if (WarehouseLockService::isLocked($pdo, $warehouseId)) {
            throw new ValidationException(["warehouse {$warehouseId} already has an active opname session"]);
        }

        $now = date('Y-m-d H:i:s');
        $uuid = self::uuid();
        $scope = $itemIds === null ? 'ALL_ACTIVE_STOCK' : 'SELECTED_ITEMS';
        $sessionNumber = NumberingService::next($pdo, 'SO', $now);
        $stmt = $pdo->prepare(
            'INSERT INTO stock_opname_sessions (warehouse_id, session_date, session_uuid, session_number, scope, status, counting_model, created_by, created_at)
             VALUES (:wh, :date, :uuid, :session_number, :scope, \'OPEN\', :counting_model, :created_by, :now)'
        );
        $stmt->execute([
            'wh' => $warehouseId, 'date' => substr($now, 0, 10), 'uuid' => $uuid,
            'session_number' => $sessionNumber, 'scope' => $scope, 'counting_model' => $countingModel, 'created_by' => $createdBy, 'now' => $now,
        ]);
        $sessionId = (int) $pdo->lastInsertId();

        if ($itemIds === null) {
            // PHASE V2.14.11.2 — URGENT HOTFIX: a FINDINGS_V1 physical
            // Stock Opname must cover every ACTIVE master item, not just
            // items with a nonzero system batch balance — a zero-system
            // SKU can still hold real physical stock (that's exactly the
            // kind of discrepancy a physical count exists to catch), and
            // excluding it would silently drop it from the count
            // altogether. LEGACY_DUAL_COUNT's default selection is left
            // untouched to minimize regression risk on the existing
            // workflow.
            if ($countingModel === 'FINDINGS_V1') {
                $scan = $pdo->prepare("SELECT id AS item_id FROM items WHERE status = 'ACTIVE' ORDER BY id");
                $scan->execute();
            } else {
                $scan = $pdo->prepare('SELECT DISTINCT item_id FROM inventory_batches WHERE warehouse_id = :wh AND qty_base <> 0');
                $scan->execute(['wh' => $warehouseId]);
            }
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

        // PHASE V2.14.10.1 Gate 1 — freeze every session item's unit
        // conversions RIGHT NOW, at session creation, before anyone can
        // start counting. Only meaningful for FINDINGS_V1 — a
        // LEGACY_DUAL_COUNT session never uses findings/units at all and
        // gets its snapshot later, if and when it is explicitly upgraded
        // (see upgradeToFindingsMode()).
        if ($countingModel === 'FINDINGS_V1') {
            self::snapshotSessionUnits($pdo, $sessionId, $now);
        }

        AuditService::log($pdo, $createdBy, 'system', 'STOCK_OPNAME_START', 'stock_opname_sessions', $sessionId, null, ['warehouse_id' => $warehouseId, 'item_count' => count($itemIds), 'scope' => $scope, 'session_number' => $sessionNumber, 'counting_model' => $countingModel], null);

        return $sessionId;
    }

    /**
     * PHASE V2.14.10.1 Gate 1 — snapshot EVERY unit currently valid (as of
     * $asOf) for every item already on this session's lines into
     * stock_opname_line_units, once. This is the ONLY unit source
     * submitFinding() ever reads once a session is FINDINGS_V1 — a
     * mid-session change to item_unit_conversions can never alter what an
     * already-open session computes for either team, because this table is
     * never re-read from the live conversions after this call. A new
     * session started after a conversion change naturally picks up the new
     * factor, since it snapshots fresh at its own start/upgrade time.
     * Idempotent (ON DUPLICATE KEY UPDATE no-op) so a defensive re-call
     * (e.g. a retried upgrade) can never duplicate or corrupt a snapshot
     * already taken.
     */
    private static function snapshotSessionUnits(PDO $pdo, int $sessionId, string $asOf): void
    {
        $lines = $pdo->prepare('SELECT id, item_id FROM stock_opname_lines WHERE session_id = :sid');
        $lines->execute(['sid' => $sessionId]);
        $lines = $lines->fetchAll();
        if ($lines === []) {
            return;
        }

        $convStmt = $pdo->prepare(
            'SELECT c.unit_id, u.code, u.name, c.conversion_to_base, i.base_unit_id
             FROM item_unit_conversions c
             JOIN units u ON u.id = c.unit_id
             JOIN items i ON i.id = c.item_id
             WHERE c.item_id = :item_id AND c.valid_from <= :as_of AND (c.valid_to IS NULL OR c.valid_to > :as_of2)'
        );
        $insert = $pdo->prepare(
            'INSERT INTO stock_opname_line_units
                (session_id, stock_opname_line_id, item_id, unit_id, unit_code_snapshot, unit_name_snapshot, conversion_factor_snapshot, is_base_unit, snapshot_at)
             VALUES (:sid, :line_id, :item_id, :unit_id, :code, :name, :factor, :is_base, :now)
             ON DUPLICATE KEY UPDATE unit_id = unit_id'
        );
        foreach ($lines as $line) {
            $convStmt->execute(['item_id' => $line['item_id'], 'as_of' => $asOf, 'as_of2' => $asOf]);
            foreach ($convStmt->fetchAll() as $c) {
                $insert->execute([
                    'sid' => $sessionId, 'line_id' => $line['id'], 'item_id' => $line['item_id'],
                    'unit_id' => $c['unit_id'], 'code' => $c['code'], 'name' => $c['name'],
                    'factor' => $c['conversion_to_base'],
                    'is_base' => ((int) $c['unit_id'] === (int) $c['base_unit_id']) ? 1 : 0,
                    'now' => $asOf,
                ]);
            }
        }
    }

    /**
     * PHASE V2.14.10.1 Gate 2 — explicit, supervisor-only, one-way upgrade
     * of a LEGACY_DUAL_COUNT session (every pre-V2.14.10.1 session, and any
     * V2.14.10 team session that hasn't been touched by this action) to
     * FINDINGS_V1 — the append-only multi-unit team model. Deliberately
     * NEVER automatic (Gate 2's "CURRENT OPEN SESSION" requirement):
     *   - refused unless the session is still OPEN.
     *   - refused unless BOTH sides have zero submitted counts so far
     *     (p1_qty_base and p2_qty_base are NULL on every line) — an upgrade
     *     after any real counting has started would leave that legacy
     *     write-once data orphaned from the new append-only model, so it is
     *     never allowed; the caller must instead let this session finish on
     *     LEGACY_DUAL_COUNT or start a fresh session.
     *   - freezes the unit-conversion snapshot (Gate 1) as of THIS moment
     *     (not the session's original start time), since counting under
     *     the new model begins now.
     *   - keeps the exact same session id and lines — never a cancel/
     *     recreate — so anything already referencing this session (team
     *     assignments, audit history) stays valid.
     *   - idempotent replay: calling it again on an already-FINDINGS_V1
     *     session is a no-op, not an error.
     */
    public static function upgradeToFindingsMode(PDO $pdo, int $sessionId, int $userId): array
    {
        $session = self::requireStatus($pdo, $sessionId, 'OPEN');
        if ($session['counting_model'] === 'FINDINGS_V1') {
            return self::get($pdo, $sessionId); // idempotent replay
        }

        $counted = $pdo->prepare(
            'SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = :id AND (p1_qty_base IS NOT NULL OR p2_qty_base IS NOT NULL)'
        );
        $counted->execute(['id' => $sessionId]);
        if ((int) $counted->fetchColumn() > 0) {
            throw new ValidationException(['cannot upgrade to Team/Findings mode — this session already has at least one submitted P1 or P2 count; finish it under the legacy model or start a new session instead']);
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE stock_opname_sessions SET counting_model = 'FINDINGS_V1' WHERE id = :id")->execute(['id' => $sessionId]);
        self::snapshotSessionUnits($pdo, $sessionId, $now);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_UPGRADE_FINDINGS_MODE', 'stock_opname_sessions', $sessionId, ['counting_model' => $session['counting_model']], ['counting_model' => 'FINDINGS_V1'], null);

        return self::get($pdo, $sessionId);
    }

    /**
     * PHASE V2.14.10.1 Gate 1 — the frozen unit list for one item within
     * one session, for the counter UI to render dynamic unit inputs from.
     * This is the ONLY unit source a FINDINGS_V1 counting screen may use
     * once the session has started — never GET /items/{id}/units again.
     *
     * @return array<int, array{unit_id:int, code:string, name:string, conversion_to_base:float, is_base_unit:bool}>
     */
    public static function getSnapshotUnitsForItem(PDO $pdo, int $sessionId, int $itemId): array
    {
        $line = $pdo->prepare('SELECT id FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item');
        $line->execute(['sid' => $sessionId, 'item' => $itemId]);
        $lineId = $line->fetchColumn();
        if ($lineId === false) {
            throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
        }

        $stmt = $pdo->prepare(
            'SELECT unit_id, unit_code_snapshot, unit_name_snapshot, conversion_factor_snapshot, is_base_unit
             FROM stock_opname_line_units WHERE stock_opname_line_id = :id ORDER BY conversion_factor_snapshot DESC'
        );
        $stmt->execute(['id' => $lineId]);
        return array_map(static fn (array $r): array => [
            'unit_id' => (int) $r['unit_id'],
            'code' => $r['unit_code_snapshot'],
            'name' => $r['unit_name_snapshot'],
            'conversion_to_base' => (float) $r['conversion_factor_snapshot'],
            'is_base_unit' => (bool) $r['is_base_unit'],
        ], $stmt->fetchAll());
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
        self::attachWorkflowMode($session);
        return $session;
    }

    /**
     * HOTFIX (post-274dc78) — a session's dual-count/legacy nature must
     * never be inferred from whether P1/P2 happen to be assigned yet: a
     * brand-new V2.12+ session starts with both NULL, which is
     * indistinguishable from a pre-V2.12 legacy session on that signal
     * alone. session_number is only ever populated by start() from
     * V2.12A onward (via NumberingService) and is never backfilled onto
     * pre-existing rows, so it is the correct, permanent discriminator:
     * NULL = genuinely legacy (created before dual-count existed), never
     * NULL = a real V2.12+ session, always DUAL_COUNT regardless of
     * whether P1/P2 have been assigned or have counted yet.
     */
    private static function attachWorkflowMode(array &$session): void
    {
        $isLegacy = $session['session_number'] === null;
        $session['is_legacy'] = $isLegacy;
        $session['workflow_mode'] = $isLegacy ? 'LEGACY_SINGLE' : 'DUAL_COUNT';
        // PHASE V2.14.10.1 Gate 2 — orthogonal to workflow_mode above: a
        // session predating this column (impossible in practice, since the
        // ALTER's DEFAULT backfills every row) would read as
        // LEGACY_DUAL_COUNT here too, which is the correct, safe default.
        $session['counting_model'] = $session['counting_model'] ?? 'LEGACY_DUAL_COUNT';
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
    public static function getForCounter(PDO $pdo, int $sessionId, string $role, int $userId = 0): array
    {
        if (!in_array($role, ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        $session = $pdo->prepare('SELECT id, warehouse_id, session_date, session_number, scope, status, counting_model, p1_user_id, p2_user_id FROM stock_opname_sessions WHERE id = :id');
        $session->execute(['id' => $sessionId]);
        $session = $session->fetch();
        if (!$session) {
            throw new NotFoundException('opname session not found');
        }

        $myQtyCol = "{$role}_qty_base";
        $mySubmittedCol = "{$role}_submitted_at";
        $myRusakCol = "{$role}_rusak_qty";
        $myExpiredCol = "{$role}_expired_qty";
        $myDeadstockCol = "{$role}_deadstock_qty";
        $myNotesCol = "{$role}_notes";
        $myUserCol = "{$role}_user_id";
        $myClaimedByCol = "{$role}_claimed_by_user_id";
        $myClaimedAtCol = "{$role}_claimed_at";
        // PHASE V2.14.10.1 Gate 5 — finding_count is computed ONCE for the
        // whole list via this GROUP BY subquery (a single extra query
        // total), never per-line: the earlier design called
        // getFindingsForLine() once per row here, an O(n) query/payload
        // pattern that would grow badly at 1000+ SKUs. This list carries
        // only the COUNT — the full finding history is fetched on demand
        // via getMyFindingsForItem() (GET .../my-findings), only for the
        // one line a counter actually opens.
        $lines = $pdo->prepare(
            "SELECT sol.id, sol.item_id, i.sku, i.name, i.category_id, i.status AS item_status,
                    i.base_unit_id, bu.code AS base_unit_code,
                    sol.{$myQtyCol} AS my_qty_base, sol.{$mySubmittedCol} AS my_submitted_at,
                    sol.{$myRusakCol} AS my_rusak_qty, sol.{$myExpiredCol} AS my_expired_qty,
                    sol.{$myDeadstockCol} AS my_deadstock_qty, sol.{$myNotesCol} AS my_notes,
                    sol.is_excluded, sol.{$myUserCol} AS my_counted_by_user_id, cu.username AS my_counted_by_username,
                    sol.{$myClaimedByCol} AS my_claimed_by_user_id, sol.{$myClaimedAtCol} AS my_claimed_at, clu.username AS my_claimed_by_username,
                    COALESCE(fc.cnt, 0) AS finding_count
             FROM stock_opname_lines sol
             JOIN items i ON i.id = sol.item_id
             JOIN units bu ON bu.id = i.base_unit_id
             LEFT JOIN users cu ON cu.id = sol.{$myUserCol}
             LEFT JOIN users clu ON clu.id = sol.{$myClaimedByCol}
             LEFT JOIN (
                 SELECT stock_opname_line_id, COUNT(*) AS cnt
                 FROM stock_opname_findings
                 WHERE session_id = :sid2 AND team_role = :role_upper AND voided_at IS NULL
                 GROUP BY stock_opname_line_id
             ) fc ON fc.stock_opname_line_id = sol.id
             WHERE sol.session_id = :id ORDER BY i.name"
        );
        $lines->execute(['id' => $sessionId, 'sid2' => $sessionId, 'role_upper' => strtoupper($role)]);
        $rows = $lines->fetchAll();

        // PHASE V2.14.10 — a claim is only ever meaningful while it's
        // still live (within the lease window) and the line is still
        // uncounted; an expired or already-counted claim is reported as
        // "not claimed" here so the UI never shows a stale "sedang
        // dihitung oleh ..." for a line that's actually free or done.
        $leaseExpiry = date('Y-m-d H:i:s', time() - self::CLAIM_LEASE_SECONDS);

        // PHASE V2.14.9 — item_id/sku/name/category_id/item_status were
        // already exchanged with the client for read-only search/filter
        // display (never a system/theoretical STOCK figure); the new
        // my_rusak_qty/my_expired_qty/my_deadstock_qty/my_notes columns
        // follow the exact same "own side only" rule as my_qty_base —
        // never the other counter's values, never system_qty_base.
        // PHASE V2.14.10 — "my" now means "my TEAM" (P1/P2 can be more
        // than one user): my_qty_base is already shared by the whole
        // team (one column per line, whoever on the team submits it
        // first), so is_counted_by_me correctly means "counted by my
        // team" with zero query change; the additions here are who
        // specifically on the team did it, and whether a teammate
        // currently holds a live claim on this line — checked
        // independently of whether it already has findings, since Tambah
        // Temuan re-claims an already-counted line too (claimItem() no
        // longer requires my_qty_base IS NULL to claim).
        $formatted = array_map(function (array $r) use ($userId, $leaseExpiry): array {
            $claimLive = $r['my_claimed_by_user_id'] !== null && $r['my_claimed_at'] !== null && $r['my_claimed_at'] >= $leaseExpiry;
            return [
                'id' => (int) $r['id'],
                'item_id' => (int) $r['item_id'],
                'sku' => $r['sku'],
                'name' => $r['name'],
                'category_id' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
                'item_status' => $r['item_status'],
                // PHASE V2.14.10 — the item's OWN base unit, never a
                // hardcoded "Pcs"/"satuan terkecil": base_unit_id is the
                // immutable arithmetic anchor every conversion is
                // expressed against, but nothing in this data model
                // guarantees it is the smallest PHYSICAL unit (see the
                // V2.14.10 audit report) — the frontend must always label
                // the computed total with this item's actual base unit
                // code, whatever it is.
                'base_unit_code' => $r['base_unit_code'],
                'my_qty_base' => $r['my_qty_base'],
                'my_submitted_at' => $r['my_submitted_at'],
                'my_rusak_qty' => $r['my_rusak_qty'],
                'my_expired_qty' => $r['my_expired_qty'],
                'my_deadstock_qty' => $r['my_deadstock_qty'],
                'my_notes' => $r['my_notes'],
                'is_counted_by_me' => $r['my_qty_base'] !== null,
                'counted_by_username' => $r['my_qty_base'] !== null ? $r['my_counted_by_username'] : null,
                'is_excluded' => (bool) $r['is_excluded'],
                'claimed_by_me' => $claimLive && (int) $r['my_claimed_by_user_id'] === $userId,
                'claimed_by_teammate_username' => ($claimLive && (int) $r['my_claimed_by_user_id'] !== $userId) ? $r['my_claimed_by_username'] : null,
                // PHASE V2.14.10.1 Gate 5 — a lightweight count only (never
                // the full finding array here — see GET .../my-findings for
                // the on-demand detail a counter opens for one item at a
                // time before deciding to claim or Tambah Temuan).
                'finding_count' => (int) $r['finding_count'],
            ];
        }, $rows);

        $counted = count(array_filter($formatted, static fn ($l) => $l['is_counted_by_me'] || $l['is_excluded']));

        return [
            'session_id' => (int) $session['id'],
            'session_number' => $session['session_number'],
            'session_date' => $session['session_date'],
            'scope' => $session['scope'],
            'status' => $session['status'],
            'counting_model' => $session['counting_model'] ?? 'LEGACY_DUAL_COUNT',
            'role' => $role,
            'team' => self::getTeamMembers($pdo, $sessionId, $role),
            'progress' => ['counted' => $counted, 'total' => count($formatted)],
            'lines' => $formatted,
        ];
    }

    /**
     * PHASE V2.14.10.1 Gate 5 — the on-demand detail endpoint behind
     * getForCounter()'s lightweight finding_count: fetched only when a
     * counter actually opens one item's panel, never bundled into the bulk
     * list. Own team's findings only — same blindness contract as
     * getForCounter() itself.
     */
    public static function getMyFindingsForItem(PDO $pdo, int $sessionId, int $itemId, string $role): array
    {
        if (!in_array($role, ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        $line = $pdo->prepare('SELECT id FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item');
        $line->execute(['sid' => $sessionId, 'item' => $itemId]);
        $lineId = $line->fetchColumn();
        if ($lineId === false) {
            throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
        }
        return self::getFindingsForLine($pdo, (int) $lineId, $role, false);
    }

    /**
     * PHASE V2.14.10.1 Gate 5 — supervisor drilldown for ONE line, BOTH
     * teams, INCLUDING voided entries: loaded on demand when a supervisor
     * opens "Riwayat Temuan" for a specific SKU, never bundled into
     * review()'s bulk line list (which only ever carries a lightweight
     * per-role finding_count — see review()).
     */
    public static function getLineFindingsForSupervisor(PDO $pdo, int $sessionId, int $itemId): array
    {
        $line = $pdo->prepare('SELECT id FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item');
        $line->execute(['sid' => $sessionId, 'item' => $itemId]);
        $lineId = $line->fetchColumn();
        if ($lineId === false) {
            throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
        }
        return [
            'p1' => self::getFindingsForLine($pdo, (int) $lineId, 'p1', true),
            'p2' => self::getFindingsForLine($pdo, (int) $lineId, 'p2', true),
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

    /**
     * PHASE V2.14.10 — CORRECTED ELIGIBILITY RULE: "ANY ACTIVE USER may be
     * selected as a Stock Opname counter, provided they are explicitly
     * assigned to that Stock Opname team. Counter authority comes from the
     * SESSION ASSIGNMENT, not from promoting the person's global role."
     * This method (shared by the legacy single-assign assignCounters()
     * AND the new multi-member assignTeamMembers()) therefore only checks
     * is_active=1 — no STOCK_OPNAME_MANAGE requirement, no hard warehouse-
     * scope requirement (a supervisor may deliberately assign a user
     * across warehouses — see "Warehouse Behavior": assignment grants
     * access ONLY to this specific session, it never changes
     * users.warehouse_id). $warehouseId is accepted but unused beyond
     * this — kept as a parameter for call-site clarity/future use, not
     * removed to avoid an unrelated signature churn.
     */
    private static function assertCanCount(PDO $pdo, ?int $userId, int $warehouseId): void
    {
        if ($userId === null) {
            return; // clearing an assignment
        }
        $stmt = $pdo->prepare('SELECT u.id, u.is_active FROM users u WHERE u.id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user || (int) $user['is_active'] !== 1) {
            throw new ValidationException(["user {$userId} does not exist or is not active"]);
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
     * PHASE V2.14.10 — every ACTIVE member of one team (P1 or P2) for one
     * session. Real rows from stock_opname_team_members if any exist for
     * this (session, role); OTHERWISE a synthetic ONE-member team built
     * from the session's legacy p{1,2}_user_id column, so a pre-V2.14.10
     * session (or one not yet touched by the new team endpoint) behaves
     * exactly as it always did — a team of exactly the one person already
     * assigned. Never mixes the two sources for the same (session, role):
     * the moment ANY real row exists, the legacy column is ignored
     * entirely for that role (see assignTeamMembers() for how a legacy
     * assignment gets folded into a real row the first time the new team
     * endpoint touches that role, so it's never silently dropped).
     *
     * @return array<int, array{user_id:int, username:string, full_name:string, is_legacy_synthetic:bool}>
     */
    private static function getTeamMembers(PDO $pdo, int $sessionId, string $role): array
    {
        $stmt = $pdo->prepare(
            'SELECT stm.user_id, u.username, u.full_name
             FROM stock_opname_team_members stm
             JOIN users u ON u.id = stm.user_id
             WHERE stm.session_id = :sid AND stm.team_role = :role AND stm.active = 1
             ORDER BY u.username'
        );
        $stmt->execute(['sid' => $sessionId, 'role' => strtoupper($role)]);
        $rows = $stmt->fetchAll();
        if ($rows !== []) {
            return array_map(static fn (array $r): array => [
                'user_id' => (int) $r['user_id'], 'username' => $r['username'], 'full_name' => $r['full_name'], 'is_legacy_synthetic' => false,
            ], $rows);
        }

        $col = "{$role}_user_id";
        $session = $pdo->prepare("SELECT {$col} AS uid FROM stock_opname_sessions WHERE id = :id");
        $session->execute(['id' => $sessionId]);
        $legacyUserId = $session->fetchColumn();
        if ($legacyUserId === false || $legacyUserId === null) {
            return [];
        }
        $u = $pdo->prepare('SELECT id, username, full_name FROM users WHERE id = :id');
        $u->execute(['id' => (int) $legacyUserId]);
        $u = $u->fetch();
        if (!$u) {
            return [];
        }
        return [['user_id' => (int) $u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'is_legacy_synthetic' => true]];
    }

    /** Public read-only accessor for both teams — used by the review()/reporting layer and the eligible-counters route. */
    public static function listTeamMembers(PDO $pdo, int $sessionId): array
    {
        return ['p1' => self::getTeamMembers($pdo, $sessionId, 'p1'), 'p2' => self::getTeamMembers($pdo, $sessionId, 'p2')];
    }

    private static function isActiveTeamMember(PDO $pdo, int $sessionId, string $role, int $userId): bool
    {
        foreach (self::getTeamMembers($pdo, $sessionId, $role) as $member) {
            if ($member['user_id'] === $userId) {
                return true;
            }
        }
        return false;
    }

    /**
     * PHASE V2.14.10 — the session-scoped counter authorization check
     * index.php's inv_require_so_counter_or_permission() relies on: does
     * $userId currently belong to EITHER team for THIS session, regardless
     * of their global role? Returns 'p1' or 'p2'; throws if neither. This
     * is deliberately the ONLY thing a plain assigned counter's identity
     * grants — index.php never widens what the returned role is allowed
     * to do beyond the specific route that called this.
     */
    public static function assertIsActiveCounter(PDO $pdo, int $sessionId, int $userId): string
    {
        if (self::isActiveTeamMember($pdo, $sessionId, 'p1', $userId)) {
            return 'p1';
        }
        if (self::isActiveTeamMember($pdo, $sessionId, 'p2', $userId)) {
            return 'p2';
        }
        throw new ValidationException(['you are not an assigned counter for this session']);
    }

    /**
     * PHASE V2.14.10 — supervisor sets a team's FULL membership in one
     * call (the multi-select UI's "these N users are now on P1/P2"),
     * reconciling against whatever's currently active: a candidate not
     * yet active gets added, a currently-active member missing from
     * $userIds gets soft-removed (active=0, removed_by/removed_at set,
     * row KEPT for audit — their already-submitted lines stay correctly
     * attributed to them either way, since that identity lives on
     * stock_opname_lines.p{1,2}_user_id, not on this table).
     *
     * Rules enforced (Sections "Multi-user team assignment", "User
     * eligibility", "Team separation", "Work distribution"):
     *  - session must be OPEN.
     *  - every candidate must be is_active=1 (ANY global role).
     *  - a user active on the OTHER role for this session is rejected —
     *    same person cannot be P1 and P2 simultaneously.
     *  - cannot reconcile a role down to zero active members while that
     *    role still has outstanding (uncounted, non-excluded) lines —
     *    open work must always have someone responsible.
     *  - a legacy single assignment (stock_opname_sessions.p{role}_user_id)
     *    with no real team_members rows yet is materialized into a real
     *    row FIRST, so it is never silently dropped by this call even if
     *    the caller's $userIds also happens to include or exclude them.
     *  - removing a member clears any live claim they currently hold, so
     *    a removal never leaves a line permanently stuck as "claimed by
     *    a non-member".
     *
     * @param array<int,int> $userIds the FULL desired membership for this role
     */
    public static function assignTeamMembers(PDO $pdo, int $sessionId, string $role, array $userIds, int $assignedBy): array
    {
        $role = strtolower($role);
        if (!in_array($role, ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        $otherRole = $role === 'p1' ? 'p2' : 'p1';
        self::requireStatus($pdo, $sessionId, 'OPEN');

        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        foreach ($userIds as $uid) {
            self::assertCanCount($pdo, $uid, 0);
        }

        $otherTeamIds = array_column(self::getTeamMembers($pdo, $sessionId, $otherRole), 'user_id');
        foreach ($userIds as $uid) {
            if (in_array($uid, $otherTeamIds, true)) {
                throw new ValidationException(["user {$uid} is already assigned to " . strtoupper($otherRole) . " for this session — a user cannot be on both teams at once"]);
            }
        }

        // Materialize a legacy single-assignment into a real row before
        // reconciling, so the diff below sees the true current state.
        $realRowCount = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_team_members WHERE session_id = :sid AND team_role = :role');
        $realRowCount->execute(['sid' => $sessionId, 'role' => strtoupper($role)]);
        if ((int) $realRowCount->fetchColumn() === 0) {
            $legacy = self::getTeamMembers($pdo, $sessionId, $role);
            if ($legacy !== [] && $legacy[0]['is_legacy_synthetic']) {
                $pdo->prepare('INSERT INTO stock_opname_team_members (session_id, team_role, user_id, assigned_by) VALUES (:sid, :role, :uid, :by)')
                    ->execute(['sid' => $sessionId, 'role' => strtoupper($role), 'uid' => $legacy[0]['user_id'], 'by' => $assignedBy]);
            }
        }

        $current = self::getTeamMembers($pdo, $sessionId, $role);
        $currentIds = array_column($current, 'user_id');
        $toAdd = array_diff($userIds, $currentIds);
        $toRemove = array_diff($currentIds, $userIds);

        if ($userIds === [] && $currentIds !== []) {
            $qtyCol = "{$role}_qty_base";
            $outstanding = $pdo->prepare("SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = :id AND is_excluded = 0 AND {$qtyCol} IS NULL");
            $outstanding->execute(['id' => $sessionId]);
            if ((int) $outstanding->fetchColumn() > 0) {
                throw new ValidationException(['cannot remove every ' . strtoupper($role) . ' team member while this session still has uncounted items for that side']);
            }
        }

        $now = date('Y-m-d H:i:s');
        foreach ($toAdd as $uid) {
            $existing = $pdo->prepare('SELECT id FROM stock_opname_team_members WHERE session_id = :sid AND team_role = :role AND user_id = :uid');
            $existing->execute(['sid' => $sessionId, 'role' => strtoupper($role), 'uid' => $uid]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                // A previously-removed member re-added reactivates their
                // existing row rather than violating the unique key.
                $pdo->prepare('UPDATE stock_opname_team_members SET active = 1, assigned_by = :by, assigned_at = :now, removed_by = NULL, removed_at = NULL WHERE id = :id')
                    ->execute(['by' => $assignedBy, 'now' => $now, 'id' => $existingId]);
            } else {
                $pdo->prepare('INSERT INTO stock_opname_team_members (session_id, team_role, user_id, assigned_by, assigned_at) VALUES (:sid, :role, :uid, :by, :now)')
                    ->execute(['sid' => $sessionId, 'role' => strtoupper($role), 'uid' => $uid, 'by' => $assignedBy, 'now' => $now]);
            }
        }
        foreach ($toRemove as $uid) {
            $pdo->prepare('UPDATE stock_opname_team_members SET active = 0, removed_by = :by, removed_at = :now WHERE session_id = :sid AND team_role = :role AND user_id = :uid AND active = 1')
                ->execute(['by' => $assignedBy, 'now' => $now, 'sid' => $sessionId, 'role' => strtoupper($role), 'uid' => $uid]);
            $claimByCol = "{$role}_claimed_by_user_id";
            $claimAtCol = "{$role}_claimed_at";
            $pdo->prepare("UPDATE stock_opname_lines SET {$claimByCol} = NULL, {$claimAtCol} = NULL WHERE session_id = :sid AND {$claimByCol} = :uid")
                ->execute(['sid' => $sessionId, 'uid' => $uid]);
        }

        AuditService::log($pdo, $assignedBy, 'system', 'STOCK_OPNAME_ASSIGN_TEAM', 'stock_opname_sessions', $sessionId, ['role' => strtoupper($role), 'previous_members' => $currentIds], ['role' => strtoupper($role), 'members' => $userIds], null);

        return self::get($pdo, $sessionId);
    }

    /**
     * PHASE V2.14.10 — "Ambil Item Berikutnya" / explicit claim-by-scan.
     * Exclusive WITHIN one team only — a P1 claim never blocks P2, the two
     * sides stay fully independent/blind exactly as before. The UPDATE's
     * WHERE clause below IS the concurrency guard (Section "Concurrent
     * Safety"): it is a single atomic compare-and-swap, so of two
     * simultaneous requests for the same line, at most one can ever affect
     * a row — the loser gets a clear conflict, never a silent overwrite.
     *
     * @return array{id:int,item_id:int,sku:string,name:string,claimed_at:string}
     */
    public static function claimItem(PDO $pdo, int $sessionId, string $role, int $userId, ?int $itemId = null): array
    {
        if (!in_array($role, ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        self::requireStatus($pdo, $sessionId, 'OPEN');
        if (!self::isActiveTeamMember($pdo, $sessionId, $role, $userId)) {
            throw new ValidationException(["you are not an active " . strtoupper($role) . ' team member for this session']);
        }

        $qtyCol = "{$role}_qty_base";
        $claimByCol = "{$role}_claimed_by_user_id";
        $claimAtCol = "{$role}_claimed_at";
        $claimTokenCol = "{$role}_claim_token";
        $now = date('Y-m-d H:i:s');
        $leaseExpiry = date('Y-m-d H:i:s', time() - self::CLAIM_LEASE_SECONDS);
        // PHASE V2.14.10.1 Gate 4 — a fresh, unguessable token minted on
        // EVERY successful claim (including a re-claim by the same owner,
        // e.g. opening "Tambah Temuan" again) — submitFinding() must
        // present this exact token, verified inside its own transaction,
        // before it may write. Never reused across two different claims.
        $token = self::uuid();

        if ($itemId !== null) {
            $candidateIds = [$itemId];
        } else {
            $candidates = $pdo->prepare(
                "SELECT sol.item_id FROM stock_opname_lines sol JOIN items i ON i.id = sol.item_id
                 WHERE sol.session_id = :sid AND sol.is_excluded = 0 AND sol.{$qtyCol} IS NULL
                   AND (sol.{$claimByCol} IS NULL OR sol.{$claimByCol} = :me OR sol.{$claimAtCol} < :lease)
                 ORDER BY i.name LIMIT 20"
            );
            $candidates->execute(['sid' => $sessionId, 'me' => $userId, 'lease' => $leaseExpiry]);
            $candidateIds = array_map('intval', array_column($candidates->fetchAll(), 'item_id'));
            if ($candidateIds === []) {
                throw new ValidationException(['no available item to claim — every item is already counted, claimed by a teammate, or excluded']);
            }
        }

        foreach ($candidateIds as $tryItemId) {
            // PHASE V2.14.10 — a claim is NEVER blocked by "already has
            // findings": Tambah Temuan explicitly re-claims an
            // already-counted line to add ANOTHER finding, so this UPDATE
            // deliberately does NOT require {$qtyCol} IS NULL (unlike the
            // "next available" candidate SELECT above, which still only
            // ever offers genuinely uncounted lines for "Ambil Item
            // Berikutnya" — that action means "help find something
            // nobody's counted yet", not "let me add to something done").
            // The only real exclusivity guard is the claim itself.
            $claim = $pdo->prepare(
                "UPDATE stock_opname_lines
                 SET {$claimByCol} = :me, {$claimAtCol} = :now, {$claimTokenCol} = :token
                 WHERE session_id = :sid AND item_id = :item AND is_excluded = 0
                   AND ({$claimByCol} IS NULL OR {$claimByCol} = :me2 OR {$claimAtCol} < :lease)"
            );
            $claim->execute(['me' => $userId, 'now' => $now, 'token' => $token, 'sid' => $sessionId, 'item' => $tryItemId, 'me2' => $userId, 'lease' => $leaseExpiry]);
            if ($claim->rowCount() === 1) {
                $line = $pdo->prepare('SELECT sol.id, sol.item_id, i.sku, i.name FROM stock_opname_lines sol JOIN items i ON i.id = sol.item_id WHERE sol.session_id = :sid AND sol.item_id = :item');
                $line->execute(['sid' => $sessionId, 'item' => $tryItemId]);
                $line = $line->fetch();
                AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_CLAIM', 'stock_opname_lines', (int) $line['id'], null, ['item_id' => $tryItemId, 'role' => $role], null);
                return ['id' => (int) $line['id'], 'item_id' => (int) $line['item_id'], 'sku' => $line['sku'], 'name' => $line['name'], 'claimed_at' => $now, 'claim_token' => $token];
            }
        }

        // Every candidate lost the race — give a specific reason when a
        // single item was explicitly requested, a generic retry message
        // for the "next available" auto-pick case.
        if ($itemId !== null) {
            $check = $pdo->prepare("SELECT sol.is_excluded, u.username FROM stock_opname_lines sol LEFT JOIN users u ON u.id = sol.{$claimByCol} WHERE sol.session_id = :sid AND sol.item_id = :item");
            $check->execute(['sid' => $sessionId, 'item' => $itemId]);
            $check = $check->fetch();
            if (!$check) {
                throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
            }
            if ((int) $check['is_excluded'] === 1) {
                throw new ValidationException(['this item has been excluded from the session by a supervisor']);
            }
            throw new ValidationException(["this item is currently claimed by {$check['username']} — try again shortly or ask a supervisor to release it"]);
        }
        throw new ValidationException(['no available item to claim right now — try again shortly']);
    }

    /**
     * PHASE V2.14.10 — voluntary release of a claim the caller currently
     * holds (or, with $isSupervisorOverride, ANY claim on that line — the
     * "supervisor reassign safely" escape hatch for an unreachable
     * claimant). A no-op if nothing was actually claimed.
     */
    public static function releaseItem(PDO $pdo, int $sessionId, string $role, int $itemId, int $userId, bool $isSupervisorOverride = false): array
    {
        if (!in_array($role, ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        self::requireStatus($pdo, $sessionId, 'OPEN');
        $claimByCol = "{$role}_claimed_by_user_id";
        $claimAtCol = "{$role}_claimed_at";
        $claimTokenCol = "{$role}_claim_token";

        $line = $pdo->prepare("SELECT id FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item");
        $line->execute(['sid' => $sessionId, 'item' => $itemId]);
        $lineId = $line->fetchColumn();
        if ($lineId === false) {
            throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
        }

        $sql = "UPDATE stock_opname_lines SET {$claimByCol} = NULL, {$claimAtCol} = NULL, {$claimTokenCol} = NULL WHERE session_id = :sid AND item_id = :item";
        $params = ['sid' => $sessionId, 'item' => $itemId];
        if (!$isSupervisorOverride) {
            $sql .= " AND {$claimByCol} = :me";
            $params['me'] = $userId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 0 && !$isSupervisorOverride) {
            throw new ValidationException(['you do not currently hold a claim on this item']);
        }

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_RELEASE_CLAIM', 'stock_opname_lines', (int) $lineId, null, ['item_id' => $itemId, 'role' => $role, 'supervisor_override' => $isSupervisorOverride], null);
        return ['released' => true];
    }

    /**
     * PHASE V2.14.10 — "STOCK OPNAME SAYA": every OPEN/FINALIZED session
     * (any warehouse) where $userId currently has an active team
     * membership, with their own team's progress. Deliberately never
     * exposes the other team's identity/values — this is the counter's
     * own entry point, same blindness contract as getForCounter().
     */
    public static function mySessions(PDO $pdo, int $userId): array
    {
        $sessions = $pdo->prepare(
            "SELECT DISTINCT sos.id, sos.warehouse_id, w.name AS warehouse_name, sos.session_number, sos.status,
                    stm.team_role
             FROM stock_opname_team_members stm
             JOIN stock_opname_sessions sos ON sos.id = stm.session_id
             JOIN warehouses w ON w.id = sos.warehouse_id
             WHERE stm.user_id = :uid AND stm.active = 1 AND sos.status IN ('OPEN','FINALIZED')
             UNION
             SELECT DISTINCT sos.id, sos.warehouse_id, w.name AS warehouse_name, sos.session_number, sos.status,
                    'P1' AS team_role
             FROM stock_opname_sessions sos
             JOIN warehouses w ON w.id = sos.warehouse_id
             WHERE sos.p1_user_id = :uid2 AND sos.status IN ('OPEN','FINALIZED')
               AND NOT EXISTS (SELECT 1 FROM stock_opname_team_members x WHERE x.session_id = sos.id AND x.team_role = 'P1')
             UNION
             SELECT DISTINCT sos.id, sos.warehouse_id, w.name AS warehouse_name, sos.session_number, sos.status,
                    'P2' AS team_role
             FROM stock_opname_sessions sos
             JOIN warehouses w ON w.id = sos.warehouse_id
             WHERE sos.p2_user_id = :uid3 AND sos.status IN ('OPEN','FINALIZED')
               AND NOT EXISTS (SELECT 1 FROM stock_opname_team_members x WHERE x.session_id = sos.id AND x.team_role = 'P2')
             ORDER BY id DESC"
        );
        $sessions->execute(['uid' => $userId, 'uid2' => $userId, 'uid3' => $userId]);
        $rows = $sessions->fetchAll();

        return array_map(function (array $r) use ($pdo, $userId): array {
            $role = strtolower($r['team_role']);
            $qtyCol = "{$role}_qty_base";
            $progress = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN {$qtyCol} IS NOT NULL OR is_excluded = 1 THEN 1 ELSE 0 END) AS counted FROM stock_opname_lines WHERE session_id = :id");
            $progress->execute(['id' => $r['id']]);
            $progress = $progress->fetch();
            return [
                'session_id' => (int) $r['id'],
                'session_number' => $r['session_number'],
                'warehouse_id' => (int) $r['warehouse_id'],
                'warehouse_name' => $r['warehouse_name'],
                'status' => $r['status'],
                'role' => $role,
                'progress' => ['counted' => (int) $progress['counted'], 'total' => (int) $progress['total']],
            ];
        }, $rows);
    }

    /**
     * PHASE V2.12A — blind, one-item-at-a-time submission for the assigned
     * P1 or P2 counter (Section 6/16 — barcode-scan friendly). Write-once:
     * a genuinely different resubmission is refused (Section 6, "do not
     * overwrite"); an identical resubmission is a no-op (Section 24,
     * idempotent double-submit protection).
     *
     * PHASE V2.14.9 — $conditions optionally carries this SAME submission's
     * Rusak/Expired/Deadstock classification + a free-text note (all
     * saved together with the qty, in this one write-once call — never a
     * separately-mutable field). Each condition quantity must be >= 0 and
     * <= $qtyBase (a classification is always a SUBSET of what this
     * counter physically found — the "preferred safe model", since no
     * prior business rule existed). None of this is ever summed into
     * inventory — finalize()/post() never read these columns.
     *
     * @param string $role 'p1'|'p2'
     * @param array{rusak_qty?:float,expired_qty?:float,deadstock_qty?:float,notes?:string} $conditions
     */
    public static function submitCount(PDO $pdo, int $sessionId, string $role, int $itemId, float $qtyBase, int $userId, array $conditions = []): array
    {
        if (!in_array($role, ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        if ($qtyBase < 0) {
            throw new ValidationException(['counted quantity cannot be negative']);
        }
        [$rusakQty, $expiredQty, $deadstockQty, $notes] = self::validateConditions($conditions, $qtyBase);

        $session = self::requireStatus($pdo, $sessionId, 'OPEN');

        // PHASE V2.14.10.1 Gate 2 — the two write-models must never mix on
        // one session: a FINDINGS_V1 session (this release's default for
        // every NEW session, or a legacy session explicitly upgraded via
        // upgradeToFindingsMode()) never accepts a legacy write-once
        // submission — the caller must use POST .../findings instead. This
        // is never inferred from whether findings happen to exist; it is
        // read directly off the explicit counting_model column.
        if (($session['counting_model'] ?? 'LEGACY_DUAL_COUNT') === 'FINDINGS_V1') {
            throw new CountingModeConflictException("session {$sessionId} uses FINDINGS_V1 (team append-only findings) — legacy count/{$role} is not available; use POST /stock-opname/{$sessionId}/findings instead");
        }

        // PHASE V2.14.10 — team-based authorization: $userId must be an
        // ACTIVE member of THIS role's team (real stock_opname_team_members
        // row, or the synthetic legacy one-member fallback — see
        // getTeamMembers()). Replaces the old single
        // "=== session.p{role}_user_id" identity check, which could only
        // ever have been true for exactly one person.
        if (self::getTeamMembers($pdo, $sessionId, $role) === []) {
            throw new ValidationException(["no {$role} counter has been assigned for this session yet"]);
        }
        if (!self::isActiveTeamMember($pdo, $sessionId, $role, $userId)) {
            throw new ValidationException(["you are not an assigned {$role} counter for this session"]);
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
        $pdo->prepare(
            "UPDATE stock_opname_lines
             SET {$qtyCol} = :qty, {$userCol} = :user, {$tsCol} = :now,
                 {$role}_rusak_qty = :rusak, {$role}_expired_qty = :expired, {$role}_deadstock_qty = :deadstock, {$role}_notes = :notes,
                 {$role}_claimed_by_user_id = NULL, {$role}_claimed_at = NULL
             WHERE id = :id"
        )->execute([
            'qty' => $qtyBase, 'user' => $userId, 'now' => $now,
            'rusak' => $rusakQty, 'expired' => $expiredQty, 'deadstock' => $deadstockQty, 'notes' => $notes,
            'id' => $line['id'],
        ]);

        self::resolveMatchStatus($pdo, (int) $line['id']);
        self::resolveConditionAgreement($pdo, (int) $line['id']);

        AuditService::log($pdo, $userId, 'system', $role === 'p1' ? 'STOCK_OPNAME_P1_COUNT' : 'STOCK_OPNAME_P2_COUNT', 'stock_opname_lines', (int) $line['id'], null, ['item_id' => $itemId, 'qty_base' => $qtyBase, 'rusak_qty' => $rusakQty, 'expired_qty' => $expiredQty, 'deadstock_qty' => $deadstockQty], null);

        return self::get($pdo, $sessionId);
    }

    /**
     * PHASE V2.14.10 — "Tambah Temuan": an APPEND-ONLY counting event. Never
     * overwrites a prior submission (unlike submitCount() above, which this
     * method does not call and does not touch) — this SKU's team result is
     * the SUM of every non-voided finding, recomputed onto
     * stock_opname_lines.p{1,2}_qty_base/_rusak_qty/_expired_qty/
     * _deadstock_qty (see recomputeAggregate()) so the existing compare/
     * recount/finalize/post pipeline needs no changes at all — it only
     * ever reads those columns, exactly as before.
     *
     * PHASE V2.14.10.1 Gate 1 — multi-unit: $unitInputs is one or more
     * {unit_id, qty} pairs (e.g. 5 Karton + 4 Pcs in the SAME finding);
     * each is resolved ONLY against this session's FROZEN
     * stock_opname_line_units snapshot (never a live
     * item_unit_conversions/UnitConversionService lookup — a packaging
     * change made mid-session can never alter what this session computes)
     * and snapshotted verbatim onto stock_opname_finding_units (identical
     * "conversion snapshot" contract inventory_transaction_lines already
     * uses for every other posted transaction in this app).
     *
     * PHASE V2.14.10.1 Gate 2 — refuses to run at all unless this session
     * is FINDINGS_V1; never silently converts a LEGACY_DUAL_COUNT session.
     *
     * PHASE V2.14.11 CORRECTION — every condition (GOOD/DAMAGED/EXPIRED/
     * DEADSTOCK), not just GOOD, now carries its OWN independent multi-unit
     * breakdown: $conditionInputs must supply all four keys, each an array
     * of one or more {unit_id, qty} pairs (the explicit-zero philosophy
     * V2.14.9.2 established for the legacy scalar fields, generalized: a
     * condition with nothing found is an explicit single {unit_id, qty:0}
     * row, never an omitted key). PHYSICAL = GOOD + DAMAGED + EXPIRED +
     * DEADSTOCK (a straight sum of independent categories, not "Rusak is a
     * subset of Good" as the old scalar model implied) — see
     * resolveConditionUnits() for the shared per-condition resolution
     * logic and recomputeAggregate() for how the four totals roll onto
     * stock_opname_lines' existing p{1,2}_qty_base/_rusak_qty/_expired_qty/
     * _deadstock_qty cache columns, unchanged in shape, so finalize()/
     * post()/review()/resolveConditionAgreement() need no changes at all.
     *
     * PHASE V2.14.10.1 Gate 1 — every unit is resolved ONLY against this
     * session's FROZEN stock_opname_line_units snapshot, never a live
     * item_unit_conversions lookup. Gate 2 — refuses to run unless this
     * session is FINDINGS_V1. Gate 3 — the FIRST active finding for this
     * (line, role) may total physical=0 (a genuine "checked, found
     * nothing" result); an ADDITIONAL finding must total physical > 0.
     * Gate 4 — $claimToken must match the live claim this exact (line,
     * role, user) currently holds, verified with the line row LOCKED
     * (SELECT ... FOR UPDATE) inside this same transaction. Gate 7 — a
     * unit_id may not repeat WITHIN one condition's own array (repeating
     * the same unit across two DIFFERENT conditions, e.g. Kg in both GOOD
     * and DAMAGED, is fine — they are independent categories).
     *
     * PHOTO REQUIREMENT [PHASE V2.14.11.1 CORRECTIVE] — for every
     * condition among DAMAGED/EXPIRED/DEADSTOCK whose total is > 0, the
     * caller must EXPLICITLY name at least one pending photo's
     * upload_token in $photoTokens[condition] — never inferred by
     * matching every still-unattached upload for this session/line/role/
     * condition/uploader (that auto-match design was found unsafe by
     * audit: an abandoned/reconsidered upload could silently attach
     * itself to a later, unrelated finding). See
     * StockOpnamePhotoService::attachExplicit(), which re-verifies the
     * full ownership chain for every token and is the ONLY thing that
     * ever sets finding_id on a photo row. A missing/invalid photo
     * refuses the ENTIRE finding (nothing partially saved) — and because
     * this runs inside the same DB transaction as the finding/quantity
     * inserts, a rollback of either leaves every named photo untouched
     * and still pending/retryable.
     *
     * @param array{GOOD:array<int,array{unit_id:mixed,qty:mixed}>, DAMAGED:array<int,array{unit_id:mixed,qty:mixed}>, EXPIRED:array<int,array{unit_id:mixed,qty:mixed}>, DEADSTOCK:array<int,array{unit_id:mixed,qty:mixed}>} $conditionInputs
     * @param array{DAMAGED?:string[], EXPIRED?:string[], DEADSTOCK?:string[]} $photoTokens upload_token(s) per condition, explicitly naming which pending uploads belong to THIS finding
     */
    public static function submitFinding(PDO $pdo, int $sessionId, string $role, int $itemId, array $conditionInputs, ?string $notes, int $userId, string $claimToken, array $photoTokens = []): array
    {
        if (!in_array($role, ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        $session = self::requireStatus($pdo, $sessionId, 'OPEN');
        if (($session['counting_model'] ?? 'LEGACY_DUAL_COUNT') !== 'FINDINGS_V1') {
            throw new CountingModeConflictException("session {$sessionId} uses LEGACY_DUAL_COUNT — findings are not available on this session and it is never silently auto-converted; use POST /stock-opname/{$sessionId}/count/{$role} instead, or ask a supervisor to upgrade this session first");
        }
        if (!self::isActiveTeamMember($pdo, $sessionId, $role, $userId)) {
            throw new ValidationException(['you are not an assigned ' . strtoupper($role) . ' counter for this session']);
        }
        foreach (['GOOD', 'DAMAGED', 'EXPIRED', 'DEADSTOCK'] as $ct) {
            if (!isset($conditionInputs[$ct]) || !is_array($conditionInputs[$ct]) || $conditionInputs[$ct] === []) {
                throw new ValidationException(["{$ct} is required (use a single entry with qty 0 if none found)"]);
            }
        }
        if (trim($claimToken) === '') {
            throw new ValidationException(['claim_token is required — claim this item before saving']);
        }
        $notes = $notes !== null && trim($notes) !== '' ? substr(trim($notes), 0, 255) : null;

        // PHASE V2.14.10.1 Gate 4 — lock the line row for the remainder of
        // this transaction so a concurrent claim/submit on the SAME line
        // cannot interleave with the ownership check below.
        $line = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item FOR UPDATE');
        $line->execute(['sid' => $sessionId, 'item' => $itemId]);
        $line = $line->fetch();
        if (!$line) {
            throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
        }
        if ((int) $line['is_excluded'] === 1) {
            throw new ValidationException(['this item has already been excluded from the session by a supervisor']);
        }

        $claimByCol = "{$role}_claimed_by_user_id";
        $claimAtCol = "{$role}_claimed_at";
        $claimTokenCol = "{$role}_claim_token";
        $leaseExpiry = date('Y-m-d H:i:s', time() - self::CLAIM_LEASE_SECONDS);
        if ((int) ($line[$claimByCol] ?? 0) !== $userId
            || $line[$claimTokenCol] === null || !hash_equals((string) $line[$claimTokenCol], $claimToken)
            || $line[$claimAtCol] === null || $line[$claimAtCol] < $leaseExpiry
        ) {
            throw new ClaimConflictException('you no longer hold a valid claim on this item — claim it again before saving');
        }

        // PHASE V2.14.10.1 Gate 1 — resolve every unit against THIS line's
        // frozen snapshot only.
        $snapshotStmt = $pdo->prepare('SELECT unit_id, conversion_factor_snapshot, unit_code_snapshot, unit_name_snapshot FROM stock_opname_line_units WHERE stock_opname_line_id = :id');
        $snapshotStmt->execute(['id' => $line['id']]);
        $snapshot = [];
        foreach ($snapshotStmt->fetchAll() as $s) {
            $snapshot[(int) $s['unit_id']] = $s;
        }

        $resolvedByCondition = [];
        $totalsByCondition = [];
        foreach (['GOOD', 'DAMAGED', 'EXPIRED', 'DEADSTOCK'] as $ct) {
            [$resolved, $total] = self::resolveConditionUnits($conditionInputs[$ct], $snapshot, $ct);
            $resolvedByCondition[$ct] = $resolved;
            $totalsByCondition[$ct] = $total;
        }
        $physicalTotal = round(array_sum($totalsByCondition), 6);

        // PHASE V2.14.10.1 Gate 3 — first-active-finding zero exception,
        // now over the PHYSICAL total (GOOD+DAMAGED+EXPIRED+DEADSTOCK),
        // not just GOOD alone.
        $isFirstFinding = self::countActiveFindings($pdo, (int) $line['id'], $role) === 0;
        if (!$isFirstFinding && $physicalTotal <= 0.0) {
            throw new ValidationException(['an additional finding (Tambah Temuan) must record a total physical quantity greater than zero — the zero-count result is only valid for the very first finding on an item']);
        }

        // PHOTO REQUIREMENT [V2.14.11.1] — checked before any write: every
        // condition with a positive total must have at least one
        // EXPLICITLY submitted photo token. Not yet verified against the
        // DB here (attachExplicit() below does that, inside the write
        // transaction) — this is only the "was anything named at all"
        // presence check, so an empty/missing photos[condition] fails
        // fast with a clear message before the finding is even inserted.
        foreach (['DAMAGED', 'EXPIRED', 'DEADSTOCK'] as $ct) {
            if ($totalsByCondition[$ct] <= 0.0) {
                continue;
            }
            if (empty($photoTokens[$ct])) {
                throw new ValidationException(["{$ct} photo evidence is required when its quantity is greater than zero — attach at least one photo before saving"]);
            }
        }

        $counterUsername = $pdo->prepare('SELECT username FROM users WHERE id = :id');
        $counterUsername->execute(['id' => $userId]);
        $counterUsername = (string) $counterUsername->fetchColumn();

        $now = date('Y-m-d H:i:s');
        $insertFinding = $pdo->prepare(
            'INSERT INTO stock_opname_findings
                (session_id, stock_opname_line_id, team_role, counter_user_id, round, counter_username_snapshot,
                 finding_good_base_qty, finding_damaged_base_qty, finding_expired_base_qty, finding_deadstock_base_qty,
                 notes, created_at)
             VALUES (:sid, :line_id, :role, :user, 1, :username,
                     :good, :damaged, :expired, :deadstock,
                     :notes, :now)'
        );
        $insertFinding->execute([
            'sid' => $sessionId, 'line_id' => $line['id'], 'role' => strtoupper($role), 'user' => $userId, 'username' => $counterUsername,
            'good' => $totalsByCondition['GOOD'], 'damaged' => $totalsByCondition['DAMAGED'],
            'expired' => $totalsByCondition['EXPIRED'], 'deadstock' => $totalsByCondition['DEADSTOCK'],
            'notes' => $notes, 'now' => $now,
        ]);
        $findingId = (int) $pdo->lastInsertId();

        $insertQty = $pdo->prepare(
            'INSERT INTO stock_opname_finding_quantities
                (finding_id, condition_type, unit_id, unit_code_snapshot, unit_name_snapshot, input_qty, conversion_factor_snapshot, base_qty_contribution)
             VALUES (:finding_id, :ct, :unit_id, :code, :name, :qty, :factor, :contribution)'
        );
        foreach ($resolvedByCondition as $ct => $units) {
            foreach ($units as $ru) {
                $insertQty->execute([
                    'finding_id' => $findingId, 'ct' => $ct, 'unit_id' => $ru['unit_id'],
                    'code' => $ru['unit_code'], 'name' => $ru['unit_name'],
                    'qty' => $ru['input_qty'], 'factor' => $ru['factor'], 'contribution' => $ru['contribution'],
                ]);
            }
        }

        // [V2.14.11.1] Attach ONLY the explicitly named tokens — never any
        // other pending photo, whatever else happens to be unattached for
        // this session/line/role/condition/uploader. attachExplicit()
        // re-verifies the full ownership chain per token and throws (the
        // whole transaction then rolls back, leaving every named photo
        // untouched and retryable) if any token fails any check.
        foreach (['DAMAGED', 'EXPIRED', 'DEADSTOCK'] as $ct) {
            if (empty($photoTokens[$ct])) {
                continue;
            }
            StockOpnamePhotoService::attachExplicit($pdo, $photoTokens[$ct], $findingId, $sessionId, (int) $line['id'], $role, $ct, $userId);
        }

        // PHASE V2.14.10.1 Gate 4 — clear the claim ONLY if it still
        // matches this exact caller+token (belt-and-suspenders: the
        // FOR UPDATE lock above already guarantees this, but the WHERE
        // clause documents the exact contract and stays correct even if
        // the locking strategy above ever changes).
        $pdo->prepare("UPDATE stock_opname_lines SET {$claimByCol} = NULL, {$claimAtCol} = NULL, {$claimTokenCol} = NULL WHERE id = :id AND {$claimByCol} = :me AND {$claimTokenCol} = :token")
            ->execute(['id' => $line['id'], 'me' => $userId, 'token' => $claimToken]);

        // PHASE V2.14.10.1 Gate 8 — this finding just changed the role's
        // aggregate; a supervisor free-text note recorded against the
        // PRIOR total is now stale and must not silently survive.
        $pdo->prepare('UPDATE stock_opname_lines SET final_notes = NULL WHERE id = :id')->execute(['id' => $line['id']]);

        self::recomputeAggregate($pdo, (int) $line['id'], $role);
        self::resolveMatchStatus($pdo, (int) $line['id']);
        self::resolveConditionAgreement($pdo, (int) $line['id']);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_FINDING_CREATE', 'stock_opname_findings', $findingId, null, [
            'item_id' => $itemId, 'role' => strtoupper($role), 'physical_total' => $physicalTotal,
            'good' => $totalsByCondition['GOOD'], 'damaged' => $totalsByCondition['DAMAGED'],
            'expired' => $totalsByCondition['EXPIRED'], 'deadstock' => $totalsByCondition['DEADSTOCK'],
            'units' => array_map(static fn (array $byCondition) => array_map(
                static fn (array $r) => ['unit_id' => $r['unit_id'], 'qty' => $r['input_qty'], 'factor' => $r['factor']], $byCondition
            ), $resolvedByCondition),
        ], null);

        return self::getForCounter($pdo, $sessionId, $role, $userId);
    }

    /**
     * PHASE V2.14.11 — shared per-condition unit resolution: validates one
     * condition's {unit_id, qty} array against the line's frozen snapshot
     * (Gate 1), rejects a unit repeated within THIS SAME condition (Gate
     * 7 — repeating a unit across two DIFFERENT conditions is fine, they
     * are independent categories), rejects a negative or non-numeric qty,
     * and computes that condition's base-unit total. A zero-qty entry is
     * never skipped — it is a real, deliberate "checked, found none" input
     * for that unit, same as the original GOOD-only Gate 3 rule.
     *
     * @param array<int,array{unit_id:mixed,qty:mixed}> $inputs
     * @param array<int,array{unit_id:mixed,conversion_factor_snapshot:mixed,unit_code_snapshot:string,unit_name_snapshot:string}> $snapshot keyed by unit_id
     * @return array{0:array<int,array{unit_id:int,unit_code:string,unit_name:string,input_qty:float,factor:float,contribution:float}>,1:float}
     */
    private static function resolveConditionUnits(array $inputs, array $snapshot, string $conditionLabel): array
    {
        $resolved = [];
        $seenUnitIds = [];
        $total = 0.0;
        foreach ($inputs as $u) {
            $unitId = (int) ($u['unit_id'] ?? 0);
            if ($unitId <= 0) {
                throw new ValidationException(["{$conditionLabel}: unit_id is required for each unit quantity"]);
            }
            if (isset($seenUnitIds[$unitId])) {
                throw new ValidationException(["{$conditionLabel}: unit {$unitId} was supplied more than once — each unit may appear at most once per condition"]);
            }
            $seenUnitIds[$unitId] = true;
            if (!isset($u['qty']) || !is_numeric($u['qty'])) {
                throw new ValidationException(["{$conditionLabel}: a valid numeric qty is required for each unit quantity"]);
            }
            $qty = (float) $u['qty'];
            if ($qty < 0) {
                throw new ValidationException(["{$conditionLabel}: quantity cannot be negative"]);
            }
            if (!array_key_exists($unitId, $snapshot)) {
                throw new ValidationException(["{$conditionLabel}: unit {$unitId} is not part of this session's frozen unit snapshot for this item"]);
            }
            $s = $snapshot[$unitId];
            $factor = (float) $s['conversion_factor_snapshot'];
            $contribution = round($qty * $factor, 6);
            $total = round($total + $contribution, 6);
            $resolved[] = [
                'unit_id' => $unitId, 'unit_code' => $s['unit_code_snapshot'], 'unit_name' => $s['unit_name_snapshot'],
                'input_qty' => $qty, 'factor' => $factor, 'contribution' => $contribution,
            ];
        }
        return [$resolved, $total];
    }

    /** PHASE V2.14.10.1 Gate 3 — non-voided finding count for (line, role), used to decide the zero-result exception (first finding only). */
    private static function countActiveFindings(PDO $pdo, int $lineId, string $role): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_findings WHERE stock_opname_line_id = :id AND team_role = :role AND voided_at IS NULL');
        $stmt->execute(['id' => $lineId, 'role' => strtoupper($role)]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * PHASE V2.14.10 — supervisor-only correction: a finding is never
     * rewritten, only voided (kept for audit, excluded from the
     * aggregate). Clears any existing supervisor condition resolution on
     * the line (final_{field}_qty), since a resolution recorded before
     * this void may no longer reflect the corrected totals — forcing a
     * fresh, deliberate re-resolution rather than silently leaving a
     * now-possibly-wrong final value in place.
     */
    public static function voidFinding(PDO $pdo, int $sessionId, int $findingId, string $reason, int $userId): array
    {
        if (trim($reason) === '') {
            throw new ValidationException(['a void reason is required']);
        }
        self::requireStatus($pdo, $sessionId, 'OPEN');

        $finding = $pdo->prepare('SELECT * FROM stock_opname_findings WHERE id = :id AND session_id = :sid');
        $finding->execute(['id' => $findingId, 'sid' => $sessionId]);
        $finding = $finding->fetch();
        if (!$finding) {
            throw new ValidationException(["finding {$findingId} not found in this session"]);
        }
        if ($finding['voided_at'] !== null) {
            return self::get($pdo, $sessionId); // idempotent replay
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE stock_opname_findings SET voided_by = :by, voided_at = :now, void_reason = :reason WHERE id = :id')
            ->execute(['by' => $userId, 'now' => $now, 'reason' => $reason, 'id' => $findingId]);

        $lineId = (int) $finding['stock_opname_line_id'];
        $role = strtolower($finding['team_role']);
        self::recomputeAggregate($pdo, $lineId, $role);
        $pdo->prepare('UPDATE stock_opname_lines SET final_rusak_qty = NULL, final_expired_qty = NULL, final_deadstock_qty = NULL, final_notes = NULL WHERE id = :id')
            ->execute(['id' => $lineId]);
        self::resolveMatchStatus($pdo, $lineId);
        self::resolveConditionAgreement($pdo, $lineId);

        AuditService::log($pdo, $userId, 'system', 'STOCK_OPNAME_FINDING_VOID', 'stock_opname_findings', $findingId, null, ['reason' => $reason], null);

        return self::get($pdo, $sessionId);
    }

    /**
     * PHASE V2.14.10 — recomputes stock_opname_lines.p{role}_qty_base and
     * its Rusak/Expired/Deadstock aggregate columns as the SUM of that
     * role's non-voided findings for this line. If this role has NEVER
     * had a finding row on this line (a legacy write-once value, or a
     * line nobody has counted via findings at all), the legacy/current
     * value is left completely untouched — this function only ever
     * manages a line once it has become finding-based. If every finding
     * for this role has been voided, the aggregate reverts to NULL
     * (not-counted), exactly like a line that was never counted.
     */
    private static function recomputeAggregate(PDO $pdo, int $lineId, string $role): void
    {
        $qtyCol = "{$role}_qty_base";
        $rusakCol = "{$role}_rusak_qty";
        $expiredCol = "{$role}_expired_qty";
        $deadstockCol = "{$role}_deadstock_qty";
        $userCol = "{$role}_user_id";
        $tsCol = "{$role}_submitted_at";

        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_findings WHERE stock_opname_line_id = :id AND team_role = :role');
        $totalStmt->execute(['id' => $lineId, 'role' => strtoupper($role)]);
        if ((int) $totalStmt->fetchColumn() === 0) {
            return; // never finding-managed for this role — leave legacy value alone
        }

        $agg = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(finding_good_base_qty),0) AS qty, COALESCE(SUM(finding_damaged_base_qty),0) AS rusak,
                    COALESCE(SUM(finding_expired_base_qty),0) AS expired, COALESCE(SUM(finding_deadstock_base_qty),0) AS deadstock
             FROM stock_opname_findings WHERE stock_opname_line_id = :id AND team_role = :role AND voided_at IS NULL"
        );
        $agg->execute(['id' => $lineId, 'role' => strtoupper($role)]);
        $agg = $agg->fetch();

        if ((int) $agg['cnt'] === 0) {
            $pdo->prepare("UPDATE stock_opname_lines SET {$qtyCol} = NULL, {$rusakCol} = NULL, {$expiredCol} = NULL, {$deadstockCol} = NULL, {$userCol} = NULL, {$tsCol} = NULL WHERE id = :id")
                ->execute(['id' => $lineId]);
            return;
        }

        $lastFinding = $pdo->prepare(
            "SELECT counter_user_id, created_at FROM stock_opname_findings
             WHERE stock_opname_line_id = :id AND team_role = :role AND voided_at IS NULL
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $lastFinding->execute(['id' => $lineId, 'role' => strtoupper($role)]);
        $last = $lastFinding->fetch();

        $pdo->prepare(
            "UPDATE stock_opname_lines
             SET {$qtyCol} = :qty, {$rusakCol} = :rusak, {$expiredCol} = :expired, {$deadstockCol} = :deadstock,
                 {$userCol} = :user, {$tsCol} = :ts
             WHERE id = :id"
        )->execute([
            'qty' => $agg['qty'], 'rusak' => $agg['rusak'], 'expired' => $agg['expired'], 'deadstock' => $agg['deadstock'],
            'user' => $last['counter_user_id'], 'ts' => $last['created_at'], 'id' => $lineId,
        ]);
    }

    /**
     * PHASE V2.14.10 — one team's finding history for one line, each with
     * its multi-unit breakdown, for the counter's own "avoid accidental
     * duplicate counting" visibility and the supervisor's drilldown.
     * $includeVoided is true only for the supervisor path — a counter
     * never needs to see a voided entry to make their own next decision.
     *
     * @return array<int, array{id:int, good_qty:float, damaged_qty:float, expired_qty:float, deadstock_qty:float, notes:?string, counter_username:string, created_at:string, is_voided:bool, void_reason:?string, quantities:array<string,array<int,array{unit_code:string, input_qty:float}>>, photos:array<string,array<int,array{id:int,storage_path:string,caption:?string}>>}>
     */
    private static function getFindingsForLine(PDO $pdo, int $lineId, string $role, bool $includeVoided = false): array
    {
        $sql = "SELECT f.id, f.finding_good_base_qty, f.finding_damaged_base_qty, f.finding_expired_base_qty, f.finding_deadstock_base_qty,
                       f.notes, f.created_at, f.voided_at, f.void_reason,
                       COALESCE(f.counter_username_snapshot, u.username) AS counter_username
                FROM stock_opname_findings f
                JOIN users u ON u.id = f.counter_user_id
                WHERE f.stock_opname_line_id = :id AND f.team_role = :role";
        if (!$includeVoided) {
            $sql .= ' AND f.voided_at IS NULL';
        }
        $sql .= ' ORDER BY f.created_at ASC, f.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $lineId, 'role' => strtoupper($role)]);
        $findings = $stmt->fetchAll();

        $qtyStmt = $pdo->prepare(
            'SELECT condition_type, input_qty, unit_code_snapshot AS unit_code
             FROM stock_opname_finding_quantities WHERE finding_id = :id ORDER BY id ASC'
        );
        $photoStmt = $pdo->prepare(
            'SELECT id, condition_type, storage_path, caption FROM stock_opname_finding_photos WHERE finding_id = :id ORDER BY id ASC'
        );

        return array_map(static function (array $f) use ($qtyStmt, $photoStmt): array {
            $qtyStmt->execute(['id' => $f['id']]);
            $quantities = ['GOOD' => [], 'DAMAGED' => [], 'EXPIRED' => [], 'DEADSTOCK' => []];
            foreach ($qtyStmt->fetchAll() as $q) {
                $quantities[$q['condition_type']][] = ['unit_code' => $q['unit_code'], 'input_qty' => (float) $q['input_qty']];
            }
            $photoStmt->execute(['id' => $f['id']]);
            $photos = ['DAMAGED' => [], 'EXPIRED' => [], 'DEADSTOCK' => []];
            foreach ($photoStmt->fetchAll() as $p) {
                $photos[$p['condition_type']][] = ['id' => (int) $p['id'], 'storage_path' => $p['storage_path'], 'caption' => $p['caption']];
            }
            return [
                'id' => (int) $f['id'],
                'good_qty' => (float) $f['finding_good_base_qty'],
                'damaged_qty' => (float) $f['finding_damaged_base_qty'],
                'expired_qty' => (float) $f['finding_expired_base_qty'],
                'deadstock_qty' => (float) $f['finding_deadstock_base_qty'],
                'notes' => $f['notes'],
                'counter_username' => $f['counter_username'],
                'created_at' => $f['created_at'],
                'is_voided' => $f['voided_at'] !== null,
                'void_reason' => $f['void_reason'],
                'quantities' => $quantities,
                'photos' => $photos,
            ];
        }, $findings);
    }

    /**
     * @param array{rusak_qty?:mixed,expired_qty?:mixed,deadstock_qty?:mixed,notes?:mixed} $conditions
     * @return array{0:?float,1:?float,2:?float,3:?string}
     */
    /**
     * PHASE V2.14.9.2 — EXPLICIT ZERO, not optional-null, for a NEW blind
     * count submission: rusak_qty/expired_qty/deadstock_qty must each be
     * present and numeric (0 is the correct "counter explicitly found
     * none" value — never a stand-in for "not asked"). A missing key,
     * null, or blank string is REJECTED here, not silently treated as
     * "no classification". This closes the one-sided-NULL ambiguity gap
     * (P1 Expired=5, P2 Expired=blank could never disagree, so it could
     * never require resolution, so the true P1-reported 5 could vanish
     * from the final result) — every NEW line now has two real, always-
     * comparable numbers for every field, so conditionRequiresResolution()
     * (unchanged) always sees a genuine agreement or a genuine
     * disagreement, never an accidental non-comparison.
     *
     * This method is ONLY ever called from submitCount() (the P1/P2
     * blind-count path). It intentionally does NOT gate
     * applyFinalConditions() (resolveConditions()/recount()'s optional,
     * per-field supervisor resolution — unchanged, still allows
     * resolving only the fields that actually need it) or the legacy
     * single-count path (count() — never had condition fields at all).
     * A historical line submitted before this phase (NULL p{1,2}_*_qty)
     * is never rewritten and never re-validated by this method — it was
     * already written, write-once, before this check existed.
     */
    private static function validateConditions(array $conditions, float $qtyBase): array
    {
        // Backward compatibility: a caller that never attempts to use the
        // condition-classification feature at all (omits the argument
        // entirely, e.g. legacy callers predating V2.14.9) passes the
        // literal default []. That is NOT a "new count with missing
        // fields" — it's "this feature is not in use here" — so it must
        // bypass the explicit-zero requirement entirely and behave exactly
        // as it did before V2.14.9.2, writing NULL for all three fields.
        // Supplying ANY key at all (even one) means the caller IS using
        // the feature, so the full completeness requirement below applies.
        if ($conditions === []) {
            return [null, null, null, null];
        }

        $values = [];
        foreach (['rusak_qty' => 'Rusak', 'expired_qty' => 'Expired', 'deadstock_qty' => 'Deadstock'] as $key => $label) {
            if (!array_key_exists($key, $conditions) || $conditions[$key] === null || $conditions[$key] === '') {
                throw new ValidationException(["{$label} is required (use 0 if none found)"]);
            }
            if (!is_numeric($conditions[$key])) {
                throw new ValidationException(["{$label} must be a valid number"]);
            }
            $qty = (float) $conditions[$key];
            if ($qty < 0) {
                throw new ValidationException(["{$label} cannot be negative"]);
            }
            if ($qty - $qtyBase > self::QTY_EPSILON) {
                throw new ValidationException(["{$label} ({$qty}) cannot exceed the counted Qty Hitung ({$qtyBase})"]);
            }
            $values[$key] = $qty;
        }
        $notes = isset($conditions['notes']) && trim((string) $conditions['notes']) !== '' ? substr(trim((string) $conditions['notes']), 0, 255) : null;

        return [$values['rusak_qty'], $values['expired_qty'], $values['deadstock_qty'], $notes];
    }

    /**
     * PHASE V2.14.9 — mirrors resolveMatchStatus()'s "never average, never
     * silently pick one side" rule for the new classification columns:
     * each of final_rusak_qty/final_expired_qty/final_deadstock_qty is set
     * ONLY when both P1 and P2 have submitted AND agree on that specific
     * value (independently per field — Rusak can resolve while Expired
     * stays open, etc.). On any disagreement (or while only one side has
     * submitted) the final_* column is (re)set to NULL, never guessed —
     * the supervisor review screen is expected to show both raw sides in
     * that case, never a single resolved-looking number.
     */
    private static function resolveConditionAgreement(PDO $pdo, int $lineId): void
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE id = :id');
        $stmt->execute(['id' => $lineId]);
        $line = $stmt->fetch();
        if (!$line || (int) $line['is_excluded'] === 1) {
            return;
        }

        $resolve = static function (?string $p1Raw, ?string $p2Raw) {
            if ($p1Raw === null || $p2Raw === null) {
                return null;
            }
            return self::qtyEquals((float) $p1Raw, (float) $p2Raw) ? (float) $p1Raw : null;
        };

        $finalRusak = $resolve($line['p1_rusak_qty'], $line['p2_rusak_qty']);
        $finalExpired = $resolve($line['p1_expired_qty'], $line['p2_expired_qty']);
        $finalDeadstock = $resolve($line['p1_deadstock_qty'], $line['p2_deadstock_qty']);

        $pdo->prepare('UPDATE stock_opname_lines SET final_rusak_qty = :r, final_expired_qty = :e, final_deadstock_qty = :d WHERE id = :id')
            ->execute(['r' => $finalRusak, 'e' => $finalExpired, 'd' => $finalDeadstock, 'id' => $lineId]);
    }

    /**
     * PHASE V2.14.9.1 — a line's Rusak/Expired/Deadstock classification
     * "requires resolution" ONLY when BOTH P1 and P2 explicitly submitted
     * a non-null value for that SPECIFIC field and those values disagree,
     * and no final value has been recorded yet (by agreement or by
     * explicit supervisor resolution). Blank/null semantics, defined
     * explicitly per the correction request:
     *   - both sides blank (null) for a field -> NOT a disagreement, no
     *     resolution required (nothing was ever compared).
     *   - only one side supplied a value for a field -> NOT treated as a
     *     disagreement either (there is nothing on the other side to
     *     disagree WITH — condition fields are optional per counter, same
     *     as the original V2.14.9 design); resolution is required only
     *     once two real, differing numbers exist to reconcile.
     *   - both sides supplied DIFFERENT non-null values -> resolution
     *     required until a supervisor explicitly records a final value
     *     (see resolveConditions()) or the values happen to already
     *     match (auto-resolved by resolveConditionAgreement()).
     * An EXCLUDED line never requires resolution (nobody was required to
     * count it in the first place).
     */
    private static function conditionRequiresResolution(array $line): bool
    {
        if ((int) $line['is_excluded'] === 1) {
            return false;
        }
        foreach (['rusak', 'expired', 'deadstock'] as $field) {
            $p1 = $line["p1_{$field}_qty"];
            $p2 = $line["p2_{$field}_qty"];
            $final = $line["final_{$field}_qty"];
            if ($p1 !== null && $p2 !== null && $final === null && !self::qtyEquals((float) $p1, (float) $p2)) {
                return true;
            }
        }
        return false;
    }

    /**
     * PHASE V2.14.9.1 — supervisor-only explicit resolution of a Rusak/
     * Expired/Deadstock (and/or Keterangan) disagreement between P1 and
     * P2. Never invoked automatically — the only other writer of
     * final_{field}_qty is resolveConditionAgreement(), which only ever
     * auto-fills a field when both sides already agree, so this method
     * never silently overwrites an auto-resolved value with a guess: it
     * always reflects a deliberate supervisor decision, one field at a
     * time or all at once, whichever the caller supplies.
     *
     * Requires the line's PHYSICAL quantity to already be resolved (MATCH
     * or RECOUNTED) — condition values are validated as a subset of that
     * resolved quantity (counted_qty_base), so resolving conditions on a
     * still-PENDING/MISMATCH qty line is refused with a clear message
     * (use recount() to resolve qty first, optionally carrying final
     * conditions in that same call — see recount()'s own docblock).
     *
     * @param array{final_rusak_qty?:mixed,final_expired_qty?:mixed,final_deadstock_qty?:mixed,final_notes?:mixed} $finalValues
     */
    public static function resolveConditions(PDO $pdo, int $sessionId, int $itemId, array $finalValues, int $userId): array
    {
        self::requireStatus($pdo, $sessionId, 'OPEN');

        $line = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :sid AND item_id = :item');
        $line->execute(['sid' => $sessionId, 'item' => $itemId]);
        $line = $line->fetch();
        if (!$line) {
            throw new ValidationException(["item {$itemId} is not part of this opname session's scope"]);
        }
        if ((int) $line['is_excluded'] === 1) {
            throw new ValidationException(['this item has been excluded from the session — condition resolution does not apply']);
        }
        if (!in_array($line['match_status'], ['MATCH', 'RECOUNTED'], true) || $line['counted_qty_base'] === null) {
            throw new ValidationException(['the physical quantity (Qty Hitung) must be resolved (MATCH or RECOUNTED) before condition values can be resolved — use recount() first if quantity also disagrees']);
        }

        self::applyFinalConditions($pdo, $line, $finalValues, $userId, 'STOCK_OPNAME_RESOLVE_CONDITIONS');

        return self::get($pdo, $sessionId);
    }

    /**
     * Shared writer for an explicit supervisor final-condition decision —
     * used by both resolveConditions() and recount() (when the latter is
     * given optional final condition values alongside a qty recount).
     * Validates each supplied field independently (>= 0, <= the line's
     * resolved physical qty — never a cross-field sum requirement, since
     * Rusak/Expired/Deadstock are independent, possibly-overlapping
     * classifications), and always writes a full audit before/after
     * snapshot via AuditService, attributing the change to $userId.
     */
    private static function applyFinalConditions(PDO $pdo, array $line, array $finalValues, int $userId, string $auditEvent): void
    {
        $qtyBase = (float) $line['counted_qty_base'];
        $before = [
            'final_rusak_qty' => $line['final_rusak_qty'], 'final_expired_qty' => $line['final_expired_qty'],
            'final_deadstock_qty' => $line['final_deadstock_qty'], 'final_notes' => $line['final_notes'],
        ];

        $sets = [];
        $params = ['id' => $line['id']];
        foreach (['final_rusak_qty' => 'Rusak', 'final_expired_qty' => 'Expired', 'final_deadstock_qty' => 'Deadstock'] as $col => $label) {
            if (!array_key_exists($col, $finalValues)) {
                continue;
            }
            $raw = $finalValues[$col];
            if ($raw === null || $raw === '') {
                $sets[] = "{$col} = NULL";
                continue;
            }
            if (!is_numeric($raw)) {
                throw new ValidationException(["Final {$label} must be a valid number"]);
            }
            $qty = (float) $raw;
            if ($qty < 0) {
                throw new ValidationException(["Final {$label} cannot be negative"]);
            }
            if ($qty - $qtyBase > self::QTY_EPSILON) {
                throw new ValidationException(["Final {$label} ({$qty}) cannot exceed the resolved Qty Hitung ({$qtyBase})"]);
            }
            $paramKey = "p_{$col}";
            $sets[] = "{$col} = :{$paramKey}";
            $params[$paramKey] = $qty;
        }
        if (array_key_exists('final_notes', $finalValues)) {
            $notes = $finalValues['final_notes'] !== null ? substr(trim((string) $finalValues['final_notes']), 0, 255) : null;
            $sets[] = 'final_notes = :final_notes';
            $params['final_notes'] = $notes === '' ? null : $notes;
        }
        if ($sets === []) {
            return; // nothing supplied — a no-op, not an error
        }

        $pdo->prepare('UPDATE stock_opname_lines SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

        $after = $pdo->prepare('SELECT final_rusak_qty, final_expired_qty, final_deadstock_qty, final_notes FROM stock_opname_lines WHERE id = :id');
        $after->execute(['id' => $line['id']]);
        $after = $after->fetch();

        AuditService::log($pdo, $userId, 'system', $auditEvent, 'stock_opname_lines', (int) $line['id'], $before, $after, null);
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
    /**
     * PHASE V2.14.10 — per-role team progress, for the supervisor's Team
     * Progress panel AND (a narrower, own-team-only slice of the same
     * data) the counter's own progress display. members/total/counted/
     * remaining/percent, plus a per-member counted breakdown (grouped
     * over p{1,2}_user_id — the "who counted" identity that already
     * exists on every line once a count lands).
     */
    public static function getTeamProgress(PDO $pdo, int $sessionId): array
    {
        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = :id AND is_excluded = 0');
        $totalStmt->execute(['id' => $sessionId]);
        $total = (int) $totalStmt->fetchColumn();

        $result = [];
        foreach (['p1', 'p2'] as $role) {
            $qtyCol = "{$role}_qty_base";
            $userCol = "{$role}_user_id";
            $countedStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = :id AND is_excluded = 0 AND {$qtyCol} IS NOT NULL");
            $countedStmt->execute(['id' => $sessionId]);
            $counted = (int) $countedStmt->fetchColumn();

            $perMemberStmt = $pdo->prepare(
                "SELECT u.username, COUNT(*) AS cnt FROM stock_opname_lines sol JOIN users u ON u.id = sol.{$userCol}
                 WHERE sol.session_id = :id AND sol.is_excluded = 0 AND sol.{$qtyCol} IS NOT NULL
                 GROUP BY u.username ORDER BY u.username"
            );
            $perMemberStmt->execute(['id' => $sessionId]);
            $perMember = array_map(static fn (array $r): array => ['username' => $r['username'], 'counted' => (int) $r['cnt']], $perMemberStmt->fetchAll());

            $result[$role] = [
                'members' => self::getTeamMembers($pdo, $sessionId, $role),
                'total' => $total,
                'counted' => $counted,
                'remaining' => $total - $counted,
                'progress_pct' => $total > 0 ? round($counted / $total * 100, 1) : 0.0,
                'per_member' => $perMember,
            ];
        }
        return $result;
    }

    public static function review(PDO $pdo, int $sessionId): array
    {
        $session = $pdo->prepare('SELECT * FROM stock_opname_sessions WHERE id = :id');
        $session->execute(['id' => $sessionId]);
        $session = $session->fetch();
        if (!$session) {
            throw new NotFoundException('opname session not found');
        }

        // PHASE V2.14.10.1 Gate 5 — p1_finding_count/p2_finding_count are
        // computed via these two GROUP BY subqueries (two extra queries
        // total, not one per line); the full per-line finding history
        // (formerly eager-loaded here as p1_findings/p2_findings for EVERY
        // line) now lives behind getLineFindingsForSupervisor(), fetched
        // only for the one line a supervisor actually opens.
        $lines = $pdo->prepare(
            'SELECT sol.*, i.sku, i.name, i.category_id, i.status AS item_status, u.code AS unit_code,
                    p1u.username AS p1_counter_username, p2u.username AS p2_counter_username,
                    COALESCE(f1.cnt, 0) AS p1_finding_count, COALESCE(f2.cnt, 0) AS p2_finding_count
             FROM stock_opname_lines sol
             JOIN items i ON i.id = sol.item_id
             LEFT JOIN units u ON u.id = i.base_unit_id
             LEFT JOIN users p1u ON p1u.id = sol.p1_user_id
             LEFT JOIN users p2u ON p2u.id = sol.p2_user_id
             LEFT JOIN (
                 SELECT stock_opname_line_id, COUNT(*) AS cnt FROM stock_opname_findings
                 WHERE session_id = :sid1 AND team_role = \'P1\' AND voided_at IS NULL GROUP BY stock_opname_line_id
             ) f1 ON f1.stock_opname_line_id = sol.id
             LEFT JOIN (
                 SELECT stock_opname_line_id, COUNT(*) AS cnt FROM stock_opname_findings
                 WHERE session_id = :sid2 AND team_role = \'P2\' AND voided_at IS NULL GROUP BY stock_opname_line_id
             ) f2 ON f2.stock_opname_line_id = sol.id
             WHERE sol.session_id = :id ORDER BY i.name'
        );
        $lines->execute(['id' => $sessionId, 'sid1' => $sessionId, 'sid2' => $sessionId]);
        $rows = $lines->fetchAll();

        $summary = [
            'total_items' => count($rows), 'match' => 0, 'mismatch' => 0, 'recounted' => 0,
            'not_counted' => 0, 'legacy_counted' => 0, 'excluded' => 0,
            'positive_difference_count' => 0, 'negative_difference_count' => 0,
            'estimated_adjustment_value' => 0.0,
            // PHASE V2.14.9.1 — lines whose Rusak/Expired/Deadstock
            // disagreement blocks finalize() until a supervisor explicitly
            // resolves them (see StockOpnameService::conditionRequiresResolution()).
            'condition_disagreement' => 0,
        ];
        foreach ($rows as $r) {
            if (self::conditionRequiresResolution($r)) {
                $summary['condition_disagreement']++;
            }
            switch ($r['match_status']) {
                case 'MATCH': $summary['match']++; break;
                case 'MISMATCH': $summary['mismatch']++; break;
                case 'RECOUNTED': $summary['recounted']++; break;
                case 'EXCLUDED': $summary['excluded']++; break;
                // A legacy single-count line never touches match_status — it stays
                // PENDING forever, even once counted/finalized/posted via the old
                // count() path. Only a genuinely never-counted line (is_counted=0)
                // belongs in "not_counted"; an already-counted legacy line gets its
                // own bucket so review()/finalize() never misreport an old, already-
                // resolved session as having open items (Section 10 semantics).
                default: (int) $r['is_counted'] === 1 ? $summary['legacy_counted']++ : $summary['not_counted']++; break;
            }
            if ($r['counted_qty_base'] !== null) {
                $diff = round((float) $r['counted_qty_base'] - (float) $r['system_qty_base'], 6);
                if ($diff > 0) { $summary['positive_difference_count']++; }
                if ($diff < 0) { $summary['negative_difference_count']++; }
                $summary['estimated_adjustment_value'] += $diff * (float) $r['unit_cost_base'];
            }
        }
        $summary['estimated_adjustment_value'] = round($summary['estimated_adjustment_value'], 4);

        self::attachWorkflowMode($session);

        return [
            'session' => $session,
            'summary' => $summary,
            'team_progress' => self::getTeamProgress($pdo, $sessionId),
            'lines' => array_map(function (array $r) use ($pdo): array {
                $diff = $r['counted_qty_base'] !== null ? round((float) $r['counted_qty_base'] - (float) $r['system_qty_base'], 6) : null;
                return [
                    'id' => (int) $r['id'],
                    'item_id' => (int) $r['item_id'],
                    'sku' => $r['sku'],
                    'name' => $r['name'],
                    'category_id' => $r['category_id'] !== null ? (int) $r['category_id'] : null,
                    'item_status' => $r['item_status'],
                    'unit_code' => $r['unit_code'],
                    'system_qty_base' => (float) $r['system_qty_base'],
                    'p1_qty_base' => $r['p1_qty_base'],
                    'p2_qty_base' => $r['p2_qty_base'],
                    // PHASE V2.14.10 — "who counted P1 / who counted P2",
                    // essential once a team can hold more than one member.
                    'p1_counter_username' => $r['p1_counter_username'],
                    'p2_counter_username' => $r['p2_counter_username'],
                    'match_status' => $r['match_status'],
                    'recount_qty_base' => $r['recount_qty_base'],
                    'recount_reason' => $r['recount_reason'],
                    'final_physical_qty_base' => $r['counted_qty_base'],
                    'difference_qty_base' => $diff,
                    'difference_value' => $diff !== null ? round($diff * (float) $r['unit_cost_base'], 4) : null,
                    'is_excluded' => (bool) $r['is_excluded'],
                    'notes' => $r['notes'],
                    // PHASE V2.14.9 — supervisor-only route (STOCK_OPNAME_SUPERVISE):
                    // both sides are shown deliberately, never hidden or merged,
                    // so a P1/P2 disagreement stays visible per the requirement.
                    'p1_rusak_qty' => $r['p1_rusak_qty'],
                    'p2_rusak_qty' => $r['p2_rusak_qty'],
                    'p1_expired_qty' => $r['p1_expired_qty'],
                    'p2_expired_qty' => $r['p2_expired_qty'],
                    'p1_deadstock_qty' => $r['p1_deadstock_qty'],
                    'p2_deadstock_qty' => $r['p2_deadstock_qty'],
                    'p1_notes' => $r['p1_notes'],
                    'p2_notes' => $r['p2_notes'],
                    'final_rusak_qty' => $r['final_rusak_qty'],
                    'final_expired_qty' => $r['final_expired_qty'],
                    'final_deadstock_qty' => $r['final_deadstock_qty'],
                    'final_notes' => $r['final_notes'],
                    'requires_condition_resolution' => self::conditionRequiresResolution($r),
                    // PHASE V2.14.10.1 Gate 5 — lightweight counts only;
                    // the supervisor's "Riwayat Temuan" drilldown fetches
                    // the full BOTH-teams history (including voided) on
                    // demand via getLineFindingsForSupervisor(), never
                    // bundled into this bulk list.
                    'p1_finding_count' => (int) $r['p1_finding_count'],
                    'p2_finding_count' => (int) $r['p2_finding_count'],
                ];
            }, $rows),
        ];
    }

    /**
     * PHASE V2.12B — recount for a MISMATCH line (Section 9). Preserves
     * the original P1/P2 values untouched; only ever resolves the FINAL
     * physical quantity via counted_qty_base (through resolveMatchStatus).
     *
     * PHASE V2.14.9.1 — $finalConditions optionally lets the supervisor
     * resolve Rusak/Expired/Deadstock/Keterangan in this SAME action, for
     * the case where qty was MISMATCH (needing this recount) AND the
     * condition classifications also disagree — never required: if
     * omitted, condition resolution can still be done afterward via
     * resolveConditions() once this recount has resolved the qty.
     *
     * @param array{final_rusak_qty?:mixed,final_expired_qty?:mixed,final_deadstock_qty?:mixed,final_notes?:mixed} $finalConditions
     */
    public static function recount(PDO $pdo, int $sessionId, int $itemId, float $qtyBase, string $reason, int $userId, array $finalConditions = []): array
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

        if ($finalConditions !== []) {
            $refreshed = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE id = :id');
            $refreshed->execute(['id' => $line['id']]);
            self::applyFinalConditions($pdo, $refreshed->fetch(), $finalConditions, $userId, 'STOCK_OPNAME_RESOLVE_CONDITIONS');
        }

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
        self::assertNotFindingsV1($session, 'finalized');

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

        // PHASE V2.14.9.1 — a genuine, still-unresolved Rusak/Expired/
        // Deadstock disagreement between P1 and P2 blocks finalize until a
        // supervisor explicitly resolves it (resolveConditions(), or
        // recount() carrying final conditions). Never blocks merely
        // because both sides left a field blank, or because only one side
        // supplied a value — see conditionRequiresResolution()'s docblock
        // for the exact blank/null semantics. This check is independent
        // of match_status (MATCH lines can still have a condition-only
        // disagreement), and re-derived from the actual column values
        // here rather than trusting any client-supplied state.
        $conditionCheckStmt = $pdo->prepare('SELECT * FROM stock_opname_lines WHERE session_id = :id');
        $conditionCheckStmt->execute(['id' => $sessionId]);
        $unresolvedCount = 0;
        foreach ($conditionCheckStmt->fetchAll() as $checkLine) {
            if (self::conditionRequiresResolution($checkLine)) {
                $unresolvedCount++;
            }
        }
        if ($unresolvedCount > 0) {
            throw new ValidationException(["{$unresolvedCount} item(s) have an unresolved Rusak/Expired/Deadstock disagreement between P1 and P2 — a supervisor must resolve them before finalize"]);
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
        // PHASE V2.14.11.1 — independent of finalize()'s own guard (which
        // already prevents a FINDINGS_V1 session from ever reaching
        // FINALIZED in the first place): checked here too, unconditionally,
        // before the POSTED/FINALIZED branching below, so a direct call to
        // post() can never reach StockAdjustmentService::post() for a
        // FINDINGS_V1 session under any status, including a row a future
        // bug might otherwise leave in an unexpected state.
        self::assertNotFindingsV1($session, 'posted');

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

    /**
     * PHASE V2.14.11.1 — Checkpoint A audit corrective. A FINDINGS_V1
     * session has no approved reconciliation/final-result workflow yet
     * (Checkpoint B's entire scope) — finalize() and post() each call
     * this independently, unconditionally, before any other check, so
     * neither can ever reach StockAdjustmentService::post() for a
     * FINDINGS_V1 session. LEGACY_DUAL_COUNT (and a legacy row where the
     * column reads NULL, treated the same as the column's own DEFAULT)
     * is completely unaffected.
     */
    private static function assertNotFindingsV1(array $session, string $pastTenseAction): void
    {
        if (($session['counting_model'] ?? 'LEGACY_DUAL_COUNT') === 'FINDINGS_V1') {
            throw new FindingsCheckpointBRequiredException(
                "this FINDINGS_V1 session cannot be {$pastTenseAction} until the Checkpoint B reconciliation/final-result workflow is completed"
            );
        }
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
