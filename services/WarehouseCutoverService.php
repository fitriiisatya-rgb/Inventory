<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.14 — GENERIC controlled warehouse cutover workflow (not
 * Karang-Tengah-specific; any future warehouse cutover reuses these same
 * tables/methods). Owns everything AFTER
 * WarehouseCutoverImportService::import() has populated
 * warehouse_cutover_lines verbatim from an accepted reconciliation
 * workbook: master-item matching, per-line human resolution (with full
 * audit trail), blocker-rule enforcement, the approved-opening preview,
 * and the actual transactional opening load.
 *
 * Status flow: DRAFT -> VALIDATED -> REVIEW_REQUIRED -> RECONCILED ->
 * APPROVED -> LOADED -> ACTIVATED. This service only ever advances
 * VALIDATED/REVIEW_REQUIRED/RECONCILED automatically (recomputeStatus(),
 * driven purely by whether any CRITICAL/REVIEW line is still PENDING);
 * APPROVED/LOADED are explicit human/actor actions (approve()/
 * loadOpening()) and ACTIVATED is never set by this service at all — see
 * loadOpening()'s docblock for why activation stays fully separate.
 */
final class WarehouseCutoverService
{
    public static function create(PDO $pdo, array $p): int
    {
        assert_required_fields($p, ['warehouse_id', 'source_name', 'opening_as_of', 'created_by']);
        $stmt = $pdo->prepare(
            'INSERT INTO warehouse_cutovers
                (warehouse_id, source_name, source_period_start, source_period_end, opening_as_of, created_by, notes)
             VALUES (:wid, :name, :start, :end, :as_of, :by, :notes)'
        );
        $stmt->execute([
            'wid' => (int) $p['warehouse_id'],
            'name' => (string) $p['source_name'],
            'start' => $p['source_period_start'] ?? null,
            'end' => $p['source_period_end'] ?? null,
            'as_of' => (string) $p['opening_as_of'],
            'by' => (int) $p['created_by'],
            'notes' => $p['notes'] ?? null,
        ]);
        $id = (int) $pdo->lastInsertId();
        AuditService::log($pdo, (int) $p['created_by'], $p['username'] ?? 'system', 'CUTOVER_CREATE', 'warehouse_cutovers', $id, null, ['warehouse_id' => (int) $p['warehouse_id'], 'source_name' => $p['source_name']], null);
        return $id;
    }

    public static function get(PDO $pdo, int $cutoverId): array
    {
        return self::loadCutover($pdo, $cutoverId);
    }

    /**
     * @return array{lines: list<array>, pagination: array}
     */
    public static function listLines(PDO $pdo, int $cutoverId, array $filters = []): array
    {
        self::loadCutover($pdo, $cutoverId);
        $where = ['cutover_id = :id'];
        $bind = ['id' => $cutoverId];
        if (!empty($filters['reconciliation_status'])) {
            $where[] = 'reconciliation_status = :status';
            $bind['status'] = $filters['reconciliation_status'];
        }
        if (!empty($filters['decision'])) {
            $where[] = 'decision = :decision';
            $bind['decision'] = $filters['decision'];
        }
        if (!empty($filters['mapping_status'])) {
            $where[] = 'mapping_status = :mapping_status';
            $bind['mapping_status'] = $filters['mapping_status'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(source_sku LIKE :q OR source_name LIKE :q)';
            $bind['q'] = '%' . $filters['q'] . '%';
        }
        $sql = 'SELECT * FROM warehouse_cutover_lines WHERE ' . implode(' AND ', $where) . ' ORDER BY source_row_reference ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll();
    }

    /**
     * Section 6: match each line's source_sku against the current global
     * item master by exact code. Never rewrites source_name/source_unit
     * from the master — both stay separately visible on the line. Because
     * items.sku is UNIQUE, an exact-code lookup can structurally never
     * return more than one row, so MULTIPLE_MATCH/CODE_COLLISION is never
     * produced by this implementation (defined in the schema's ENUM for a
     * future fuzzier matcher, not reachable from this one).
     */
    public static function matchItems(PDO $pdo, int $cutoverId): array
    {
        $cutover = self::loadCutover($pdo, $cutoverId);
        self::assertNotLoadedOrActivated($cutover);

        $lines = $pdo->prepare('SELECT id, source_sku, source_name, source_unit FROM warehouse_cutover_lines WHERE cutover_id = :id');
        $lines->execute(['id' => $cutoverId]);
        $lines = $lines->fetchAll();

        $itemStmt = $pdo->prepare(
            'SELECT i.id, i.name, u.code AS unit_code
               FROM items i JOIN units u ON u.id = i.base_unit_id
              WHERE i.sku = :sku'
        );
        $update = $pdo->prepare('UPDATE warehouse_cutover_lines SET item_id = :item_id, mapping_status = :status WHERE id = :id');

        $counts = ['MATCHED' => 0, 'NOT_FOUND' => 0, 'NAME_MISMATCH' => 0, 'UNIT_MISMATCH' => 0];
        foreach ($lines as $line) {
            $itemStmt->execute(['sku' => $line['source_sku']]);
            $item = $itemStmt->fetch();
            if ($item === false) {
                $update->execute(['item_id' => null, 'status' => 'NOT_FOUND', 'id' => $line['id']]);
                $counts['NOT_FOUND']++;
                continue;
            }
            $unitMatches = strcasecmp(trim((string) $item['unit_code']), trim((string) $line['source_unit'])) === 0;
            $nameMatches = strcasecmp(trim((string) $item['name']), trim((string) $line['source_name'])) === 0;
            $status = !$unitMatches ? 'UNIT_MISMATCH' : (!$nameMatches ? 'NAME_MISMATCH' : 'MATCHED');
            $update->execute(['item_id' => $item['id'], 'status' => $status, 'id' => $line['id']]);
            $counts[$status]++;
        }
        return $counts;
    }

    /**
     * Section 7: per-line resolution. Every field is independently
     * optional so a single call can be "just map to item", "just add a
     * note", or a full decision + approved qty/cost in one action — but
     * every changed field is captured old-value/new-value in the audit
     * log, and decision transitions apply the rules below (never silent).
     */
    public static function resolveLine(PDO $pdo, int $cutoverId, int $lineId, array $p): void
    {
        $cutover = self::loadCutover($pdo, $cutoverId);
        self::assertNotLoadedOrActivated($cutover);

        $stmt = $pdo->prepare('SELECT * FROM warehouse_cutover_lines WHERE id = :id AND cutover_id = :cid');
        $stmt->execute(['id' => $lineId, 'cid' => $cutoverId]);
        $before = $stmt->fetch();
        if ($before === false) {
            throw new NotFoundException("cutover line {$lineId} not found on cutover {$cutoverId}");
        }

        $itemId = array_key_exists('item_id', $p) ? ($p['item_id'] !== null ? (int) $p['item_id'] : null) : ($before['item_id'] !== null ? (int) $before['item_id'] : null);
        $decision = $p['decision'] ?? $before['decision'];
        $notes = array_key_exists('notes', $p) ? $p['notes'] : $before['notes'];

        if (!in_array($decision, ['PENDING', 'ACCEPT_SOURCE', 'BUSINESS_OVERRIDE', 'EXCLUDE'], true)) {
            throw new ValidationException(["unknown decision '{$decision}'"]);
        }

        $approvedQty = null;
        $approvedUnitCost = null;
        if ($decision === 'ACCEPT_SOURCE') {
            // Take the source's own theoretical closing figures as-is —
            // never re-derived differently than what was imported.
            $approvedQty = (float) $before['theoretical_closing_qty'];
            $approvedUnitCost = $before['source_price'] !== null ? (float) $before['source_price'] : null;
        } elseif ($decision === 'BUSINESS_OVERRIDE') {
            if (!array_key_exists('approved_qty', $p) || $p['approved_qty'] === null) {
                throw new ValidationException(['BUSINESS_OVERRIDE requires an explicit approved_qty']);
            }
            $approvedQty = (float) $p['approved_qty'];
            $approvedUnitCost = array_key_exists('approved_unit_cost', $p) && $p['approved_unit_cost'] !== null
                ? (float) $p['approved_unit_cost']
                : ($before['source_price'] !== null ? (float) $before['source_price'] : null);
        }
        // EXCLUDE / PENDING: approved_qty/approved_unit_cost stay NULL —
        // this line contributes nothing to the opening load.

        $pdo->prepare(
            'UPDATE warehouse_cutover_lines
                SET item_id = :item_id, decision = :decision,
                    approved_qty = :qty, approved_unit_cost = :cost,
                    approved_by = :by, approved_at = NOW(), notes = :notes
              WHERE id = :id'
        )->execute([
            'item_id' => $itemId,
            'decision' => $decision,
            'qty' => $approvedQty,
            'cost' => $approvedUnitCost,
            'by' => (int) $p['actor_id'],
            'notes' => $notes,
            'id' => $lineId,
        ]);

        AuditService::log(
            $pdo, (int) $p['actor_id'], $p['actor_username'] ?? 'system', 'CUTOVER_LINE_RESOLVE',
            'warehouse_cutover_lines', $lineId,
            ['item_id' => $before['item_id'], 'decision' => $before['decision'], 'approved_qty' => $before['approved_qty'], 'approved_unit_cost' => $before['approved_unit_cost'], 'notes' => $before['notes']],
            ['item_id' => $itemId, 'decision' => $decision, 'approved_qty' => $approvedQty, 'approved_unit_cost' => $approvedUnitCost, 'notes' => $notes],
            $p['reason'] ?? null
        );

        self::recomputeStatus($pdo, $cutoverId);
    }

    /** Section 7's summary-card counts, computed live from the lines. */
    public static function summary(PDO $pdo, int $cutoverId): array
    {
        self::loadCutover($pdo, $cutoverId);
        $stmt = $pdo->prepare(
            "SELECT
                SUM(reconciliation_status = 'PASS') AS pass_count,
                SUM(reconciliation_status = 'REVIEW') AS review_count,
                SUM(reconciliation_status = 'CRITICAL') AS critical_count,
                SUM(reconciliation_status = 'NO_ACTIVITY') AS no_activity_count,
                SUM(item_id IS NULL) AS unresolved_mapping_count,
                SUM(theoretical_closing_qty < 0) AS negative_closing_count,
                SUM(mapping_status = 'UNIT_MISMATCH' OR exception_codes LIKE '%SOURCE_UNIT_CONFLICT%') AS unit_conflict_count,
                SUM(exception_codes LIKE '%MISSING_PRICE_WITH_ACTUAL_MOVEMENT%') AS missing_price_count,
                SUM(exception_codes LIKE '%DUPLICATE_%') AS duplicate_impact_count,
                SUM(decision IN ('ACCEPT_SOURCE','BUSINESS_OVERRIDE')) AS approved_rows_count
             FROM warehouse_cutover_lines WHERE cutover_id = :id"
        );
        $stmt->execute(['id' => $cutoverId]);
        $row = $stmt->fetch();
        return array_map(static fn ($v) => (int) $v, $row ?: array_fill_keys([
            'pass_count', 'review_count', 'critical_count', 'no_activity_count', 'unresolved_mapping_count',
            'negative_closing_count', 'unit_conflict_count', 'missing_price_count', 'duplicate_impact_count', 'approved_rows_count',
        ], 0));
    }

    /** CRITICAL/REVIEW lines still at decision=PENDING — the blocker counts APPROVE/LOAD both key off. */
    public static function unresolvedCounts(PDO $pdo, int $cutoverId): array
    {
        $stmt = $pdo->prepare(
            "SELECT reconciliation_status, COUNT(*) AS c
               FROM warehouse_cutover_lines
              WHERE cutover_id = :id AND decision = 'PENDING' AND reconciliation_status IN ('CRITICAL','REVIEW')
              GROUP BY reconciliation_status"
        );
        $stmt->execute(['id' => $cutoverId]);
        $counts = ['critical_unresolved' => 0, 'review_unresolved' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['reconciliation_status'] === 'CRITICAL' ? 'critical_unresolved' : 'review_unresolved'] = (int) $row['c'];
        }
        return $counts;
    }

    /** Auto-advances VALIDATED/REVIEW_REQUIRED/RECONCILED only — never touches DRAFT/APPROVED/LOADED/ACTIVATED. */
    public static function recomputeStatus(PDO $pdo, int $cutoverId): void
    {
        $cutover = self::loadCutover($pdo, $cutoverId);
        if (!in_array($cutover['status'], ['VALIDATED', 'REVIEW_REQUIRED', 'RECONCILED'], true)) {
            return;
        }
        $u = self::unresolvedCounts($pdo, $cutoverId);
        $new = ($u['critical_unresolved'] > 0 || $u['review_unresolved'] > 0) ? 'REVIEW_REQUIRED' : 'RECONCILED';
        if ($new !== $cutover['status']) {
            $pdo->prepare('UPDATE warehouse_cutovers SET status = :s WHERE id = :id')->execute(['s' => $new, 'id' => $cutoverId]);
        }
    }

    /**
     * Section 4 rule: any unresolved CRITICAL blocks approval outright.
     * Safest default (explicitly requested): any unresolved REVIEW also
     * blocks approval.
     */
    public static function approve(PDO $pdo, int $cutoverId, int $actorId, string $actorUsername): void
    {
        $cutover = self::loadCutover($pdo, $cutoverId);
        self::recomputeStatus($pdo, $cutoverId);
        $cutover = self::loadCutover($pdo, $cutoverId);
        if ($cutover['status'] !== 'RECONCILED') {
            $u = self::unresolvedCounts($pdo, $cutoverId);
            throw new ValidationException([
                "cutover cannot be approved while status is '{$cutover['status']}' — {$u['critical_unresolved']} CRITICAL and {$u['review_unresolved']} REVIEW row(s) remain unresolved",
            ]);
        }
        $pdo->prepare("UPDATE warehouse_cutovers SET status = 'APPROVED', approved_by = :by, approved_at = NOW() WHERE id = :id")
            ->execute(['by' => $actorId, 'id' => $cutoverId]);
        AuditService::log($pdo, $actorId, $actorUsername, 'CUTOVER_APPROVE', 'warehouse_cutovers', $cutoverId, ['status' => 'RECONCILED'], ['status' => 'APPROVED'], null);
    }

    /**
     * Section 9: read-only preview of what loadOpening() would post, plus
     * the reconciliation equation restated against approved (possibly
     * business-overridden) figures rather than raw theoretical closing.
     * Creates nothing.
     */
    public static function previewApprovedOpening(PDO $pdo, int $cutoverId): array
    {
        self::loadCutover($pdo, $cutoverId);
        $stmt = $pdo->prepare('SELECT * FROM warehouse_cutover_lines WHERE cutover_id = :id ORDER BY source_row_reference');
        $stmt->execute(['id' => $cutoverId]);
        $lines = $stmt->fetchAll();

        $included = [];
        $excludedCount = 0;
        $unresolvedCount = 0;
        $zeroOrNegativeQtyRows = 0;
        $unitConflicts = 0;
        $unmappedItems = 0;
        $totalQty = 0.0;
        $totalValue = 0.0;
        $theoreticalQtySum = 0.0;
        $theoreticalValueSum = 0.0;

        foreach ($lines as $l) {
            if ($l['decision'] === 'PENDING') {
                $unresolvedCount++;
                continue;
            }
            if ($l['decision'] === 'EXCLUDE') {
                $excludedCount++;
                continue;
            }
            $qty = $l['approved_qty'] !== null ? (float) $l['approved_qty'] : null;
            $cost = $l['approved_unit_cost'] !== null ? (float) $l['approved_unit_cost'] : null;
            if ($qty === null || $qty <= 0) {
                $zeroOrNegativeQtyRows++;
            }
            if ($l['mapping_status'] === 'UNIT_MISMATCH') {
                $unitConflicts++;
            }
            if ($l['item_id'] === null) {
                $unmappedItems++;
            }
            $value = ($qty ?? 0) * ($cost ?? 0);
            $included[] = [
                'source_sku' => $l['source_sku'],
                'source_name' => $l['source_name'],
                'item_id' => $l['item_id'] !== null ? (int) $l['item_id'] : null,
                'decision' => $l['decision'],
                'approved_qty' => $qty,
                'approved_unit_cost' => $cost,
                'approved_value' => $value,
                'theoretical_closing_qty' => (float) $l['theoretical_closing_qty'],
                'theoretical_closing_value' => (float) $l['theoretical_closing_value'],
            ];
            $totalQty += $qty ?? 0;
            $totalValue += $value;
            $theoreticalQtySum += (float) $l['theoretical_closing_qty'];
            $theoreticalValueSum += (float) $l['theoretical_closing_value'];
        }

        return [
            'lines' => $included,
            'summary' => [
                'approved_sku_count' => count($included),
                'total_qty' => $totalQty,
                'total_value' => $totalValue,
                'excluded_sku_count' => $excludedCount,
                'unresolved_rows' => $unresolvedCount,
                'zero_or_negative_qty_rows' => $zeroOrNegativeQtyRows,
                'unit_conflicts' => $unitConflicts,
                'unmapped_items' => $unmappedItems,
                // Section 9's "reconciliation equation against approved
                // business decisions": how far APPROVED figures (after any
                // BUSINESS_OVERRIDE) have moved from the workbook's own raw
                // theoretical closing sum, across included lines only.
                'theoretical_closing_qty_sum' => $theoreticalQtySum,
                'theoretical_closing_value_sum' => $theoreticalValueSum,
                'approved_vs_theoretical_qty_delta' => $totalQty - $theoreticalQtySum,
                'approved_vs_theoretical_value_delta' => $totalValue - $theoreticalValueSum,
            ],
        ];
    }

    /**
     * Section 10/11: the actual transactional opening load — TEST DB ONLY,
     * never called against production by this codebase's own workflow (no
     * route/UI action in this phase invokes it against a production
     * warehouse; see public/index.php's SUPERADMIN-only gate on the route
     * itself).
     *
     * Uses the exact same FIFO OPENING semantics ImportOpeningStockService
     * already uses for every other warehouse's opening balance — a single
     * FifoService::postIn() per included line, transaction_type=OPENING,
     * dated at cutover.opening_as_of. This is ONE dated posting per SKU,
     * never a fabricated per-day history across the source period.
     *
     * Section 11: activation is NEVER touched here or anywhere in this
     * phase. warehouses.is_active/activation_locked are only ever changed
     * by PUT /warehouses/{id} (WarehouseGuardService, V2.13/V2.13.1), which
     * this method neither calls nor bypasses — the warehouse stays exactly
     * as locked/inactive after a successful load as it was before it.
     */
    public static function loadOpening(PDO $pdo, int $cutoverId, int $actorId, string $actorUsername): array
    {
        return Database::transaction(function (PDO $tx) use ($cutoverId, $actorId, $actorUsername) {
            $cutover = self::loadCutover($tx, $cutoverId);
            if ($cutover['status'] !== 'APPROVED') {
                throw new ValidationException(["cutover must be APPROVED to load an opening balance, currently '{$cutover['status']}'"]);
            }

            $warehouse = $tx->prepare('SELECT * FROM warehouses WHERE id = :id');
            $warehouse->execute(['id' => $cutover['warehouse_id']]);
            $warehouse = $warehouse->fetch();
            if ($warehouse === false) {
                throw new NotFoundException("warehouse {$cutover['warehouse_id']} not found");
            }
            if ((int) $warehouse['is_active'] !== 0 || (int) $warehouse['activation_locked'] !== 1) {
                throw new ValidationException(['warehouse must be inactive AND activation_locked to load a controlled-cutover opening balance']);
            }

            $u = self::unresolvedCounts($tx, $cutoverId);
            if ($u['critical_unresolved'] > 0 || $u['review_unresolved'] > 0) {
                throw new ValidationException(["cannot load: {$u['critical_unresolved']} CRITICAL and {$u['review_unresolved']} REVIEW row(s) remain unresolved"]);
            }

            $lines = $tx->prepare("SELECT * FROM warehouse_cutover_lines WHERE cutover_id = :id AND decision IN ('ACCEPT_SOURCE','BUSINESS_OVERRIDE') ORDER BY source_row_reference");
            $lines->execute(['id' => $cutoverId]);
            $lines = $lines->fetchAll();
            if (empty($lines)) {
                throw new ValidationException(['no included (ACCEPT_SOURCE/BUSINESS_OVERRIDE) line to load']);
            }

            $seenItemIds = [];
            foreach ($lines as $l) {
                if ($l['item_id'] === null) {
                    throw new ValidationException(["line {$l['source_sku']}: not mapped to an item — cannot load"]);
                }
                if (isset($seenItemIds[$l['item_id']])) {
                    throw new ValidationException(["duplicate included item_id {$l['item_id']} (SKU {$l['source_sku']}) after resolution — refusing to load"]);
                }
                $seenItemIds[$l['item_id']] = true;
                if ($l['approved_qty'] === null || (float) $l['approved_qty'] <= 0) {
                    throw new ValidationException(["line {$l['source_sku']}: approved_qty must be > 0 to load"]);
                }
                if ($l['approved_unit_cost'] === null || (float) $l['approved_unit_cost'] < 0) {
                    throw new ValidationException(["line {$l['source_sku']}: approved_unit_cost must be >= 0 to load"]);
                }
                if ($l['mapping_status'] !== 'MATCHED' && $l['decision'] !== 'BUSINESS_OVERRIDE') {
                    throw new ValidationException(["line {$l['source_sku']}: mapping_status is '{$l['mapping_status']}', not MATCHED — requires an explicit BUSINESS_OVERRIDE decision before it can load"]);
                }
            }

            $companyBefore = $tx->query('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches')->fetch(PDO::FETCH_NUM);

            $lineCount = 0;
            $totalQty = 0.0;
            $totalValue = 0.0;
            $updateBatch = $tx->prepare('UPDATE warehouse_cutover_lines SET created_batch_id = :b WHERE id = :id');
            foreach ($lines as $l) {
                $baseUnitId = self::itemBaseUnitId($tx, (int) $l['item_id']);
                $qty = (float) $l['approved_qty'];
                $cost = (float) $l['approved_unit_cost'];
                $result = FifoService::postIn($tx, [
                    'transaction_uuid' => 'CUTOVER-' . $cutoverId . '-' . $l['id'],
                    'item_id' => (int) $l['item_id'], 'warehouse_id' => (int) $cutover['warehouse_id'],
                    'input_qty' => $qty, 'input_unit_id' => $baseUnitId,
                    'unit_price_input' => $cost,
                    'allow_zero_price' => true,
                    'transaction_type' => 'OPENING',
                    'transaction_date' => $cutover['opening_as_of'] . ' 00:00:00',
                    'reference_no' => "CUTOVER-{$cutoverId}",
                    'created_by' => $actorId,
                    'anomaly_approved_by' => $actorId,
                ], bypassInactiveWarehouseGuard: true);
                $updateBatch->execute(['b' => $result['batch_id'], 'id' => $l['id']]);
                $lineCount++;
                $totalQty += $qty;
                $totalValue += $qty * $cost;
            }

            $companyAfter = $tx->query('SELECT COALESCE(SUM(qty_base),0), COALESCE(SUM(qty_base*unit_cost_base),0) FROM inventory_batches')->fetch(PDO::FETCH_NUM);

            $tx->prepare("UPDATE warehouse_cutovers SET status = 'LOADED', loaded_by = :by, loaded_at = NOW() WHERE id = :id")
                ->execute(['by' => $actorId, 'id' => $cutoverId]);

            AuditService::log(
                $tx, $actorId, $actorUsername, 'CUTOVER_LOAD', 'warehouse_cutovers', $cutoverId,
                ['status' => 'APPROVED', 'company_qty_before' => (float) $companyBefore[0], 'company_value_before' => (float) $companyBefore[1]],
                ['status' => 'LOADED', 'line_count' => $lineCount, 'total_qty' => $totalQty, 'total_value' => $totalValue, 'company_qty_after' => (float) $companyAfter[0], 'company_value_after' => (float) $companyAfter[1]],
                null
            );

            return ['line_count' => $lineCount, 'total_qty' => $totalQty, 'total_value' => $totalValue];
        });
    }

    private static function itemBaseUnitId(PDO $pdo, int $itemId): int
    {
        $stmt = $pdo->prepare('SELECT base_unit_id FROM items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
        return (int) $stmt->fetchColumn();
    }

    private static function loadCutover(PDO $pdo, int $cutoverId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM warehouse_cutovers WHERE id = :id');
        $stmt->execute(['id' => $cutoverId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException("warehouse cutover {$cutoverId} not found");
        }
        return $row;
    }

    private static function assertNotLoadedOrActivated(array $cutover): void
    {
        if (in_array($cutover['status'], ['LOADED', 'ACTIVATED'], true)) {
            throw new ValidationException(["cutover is already '{$cutover['status']}' — lines can no longer be resolved or re-matched"]);
        }
    }
}
