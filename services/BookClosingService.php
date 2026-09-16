<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE C2 Section 5. Replaces the legacy Tutup Buku, which froze an
 * ending value and then DELETED the period's transactions (Phase A
 * analysis, risky-logic item 6). This version only ever INSERTs a
 * book_closings + book_closing_lines snapshot and flips a status flag —
 * inventory_transactions rows are never touched, so "why is stock X"
 * stays answerable via the ledger for every period, closed or not.
 *
 * Known limitation (documented, not hidden): ending inventory is read from
 * the LIVE inventory_batches state at close time, not reconstructed via a
 * true point-in-time replay. close() therefore refuses to run while any
 * POSTED transaction already exists dated AFTER period_end — closings must
 * happen promptly, in period order, before later-dated data exists.
 */
final class BookClosingService
{
    public static function preview(PDO $pdo, string $periodStart, string $periodEnd): array
    {
        $blockers = self::blockers($pdo, $periodStart, $periodEnd);

        $onHand = InventoryService::companyOnHandValue($pdo);
        $inTransit = InventoryService::inTransitValue($pdo);

        $purchaseTotal = self::sumLines($pdo, 'IN', $periodStart, $periodEnd);
        $usageTotal = self::sumLines($pdo, 'OUT', $periodStart, $periodEnd);
        $shrinkageTotal = self::shrinkageTotal($pdo, $periodStart, $periodEnd);

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'ending_inventory_value' => $onHand,
            'in_transit_value' => $inTransit,
            'total_company_value' => round($onHand + $inTransit, 4),
            'purchase_total' => $purchaseTotal,
            'usage_total' => $usageTotal,
            'shrinkage_total' => $shrinkageTotal,
            'blockers' => $blockers,
            'can_close' => empty($blockers),
        ];
    }

    public static function close(PDO $pdo, string $periodStart, string $periodEnd, int $userId): array
    {
        $existing = $pdo->prepare('SELECT * FROM book_closings WHERE period_start = :s AND period_end = :e');
        $existing->execute(['s' => $periodStart, 'e' => $periodEnd]);
        $existing = $existing->fetch();
        if ($existing && $existing['status'] === 'LOCKED') {
            return ['success' => true, 'idempotent_replay' => true, 'book_closing_id' => (int) $existing['id']];
        }

        $blockers = self::blockers($pdo, $periodStart, $periodEnd);
        if (!empty($blockers)) {
            throw new ValidationException(array_merge(['book closing blocked'], $blockers));
        }

        $preview = self::preview($pdo, $periodStart, $periodEnd);
        $now = date('Y-m-d H:i:s');

        if ($existing) {
            $closingId = (int) $existing['id'];
            $pdo->prepare(
                'UPDATE book_closings SET total_closing_value = :value, total_in_transit_value = :transit,
                 purchase_total = :purchase, usage_total = :usage, shrinkage_total = :shrinkage,
                 status = \'LOCKED\', locked_by = :by, locked_at = :now WHERE id = :id'
            )->execute([
                'value' => $preview['ending_inventory_value'], 'transit' => $preview['in_transit_value'],
                'purchase' => $preview['purchase_total'], 'usage' => $preview['usage_total'], 'shrinkage' => $preview['shrinkage_total'],
                'by' => $userId, 'now' => $now, 'id' => $closingId,
            ]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO book_closings
                    (period_start, period_end, status, total_closing_value, total_in_transit_value,
                     purchase_total, usage_total, shrinkage_total, locked_by, locked_at, created_by, created_at)
                 VALUES (:s, :e, \'LOCKED\', :value, :transit, :purchase, :usage, :shrinkage, :by, :now, :created_by, :now2)'
            );
            $insert->execute([
                's' => $periodStart, 'e' => $periodEnd, 'value' => $preview['ending_inventory_value'],
                'transit' => $preview['in_transit_value'], 'purchase' => $preview['purchase_total'],
                'usage' => $preview['usage_total'], 'shrinkage' => $preview['shrinkage_total'],
                'by' => $userId, 'now' => $now, 'created_by' => $userId, 'now2' => $now,
            ]);
            $closingId = (int) $pdo->lastInsertId();
        }

        // Snapshot ending qty/value per item+warehouse (the "Opening October" any later period reads back).
        $pdo->prepare('DELETE FROM book_closing_lines WHERE book_closing_id = :id')->execute(['id' => $closingId]);
        $lines = $pdo->query(
            'SELECT item_id, warehouse_id, SUM(qty_base) AS qty, SUM(qty_base * unit_cost_base) AS value
             FROM inventory_batches WHERE qty_base <> 0 GROUP BY item_id, warehouse_id'
        )->fetchAll();
        $lineStmt = $pdo->prepare(
            'INSERT INTO book_closing_lines (book_closing_id, item_id, warehouse_id, qty_base, unit_cost_base_avg, value)
             VALUES (:id, :item_id, :wh, :qty, :avg_cost, :value)'
        );
        foreach ($lines as $line) {
            $qty = (float) $line['qty'];
            $value = (float) $line['value'];
            $lineStmt->execute([
                'id' => $closingId, 'item_id' => $line['item_id'], 'wh' => $line['warehouse_id'],
                'qty' => round($qty, 6), 'avg_cost' => $qty != 0 ? round($value / $qty, 4) : 0,
                'value' => round($value, 4),
            ]);
        }

        // Mark every transaction dated within the period as belonging to this closing (drives PeriodLockService).
        $pdo->prepare('UPDATE inventory_transactions SET book_closing_id = :id WHERE transaction_date BETWEEN :s AND :e')
            ->execute(['id' => $closingId, 's' => $periodStart, 'e' => $periodEnd . ' 23:59:59']);

        AuditService::log($pdo, $userId, 'system', 'BOOK_CLOSE', 'book_closings', $closingId, null, $preview, null);

        return ['success' => true, 'book_closing_id' => $closingId] + $preview;
    }

    public static function listAll(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM book_closings ORDER BY period_start DESC')->fetchAll();
    }

    /** @return string[] human-readable blocking reasons; empty = clear to close */
    private static function blockers(PDO $pdo, string $periodStart, string $periodEnd): array
    {
        $blockers = [];

        $pendingTransfers = $pdo->prepare(
            "SELECT COUNT(*) FROM warehouse_transfers WHERE status = 'PENDING' AND ship_date <= :end"
        );
        $pendingTransfers->execute(['end' => $periodEnd . ' 23:59:59']);
        if ((int) $pendingTransfers->fetchColumn() > 0) {
            $blockers[] = 'one or more warehouse transfers are still PENDING (in transit) with a ship date inside this period';
        }

        $activeOpname = $pdo->query("SELECT COUNT(*) FROM stock_opname_sessions WHERE status IN ('OPEN','FINALIZED')")->fetchColumn();
        if ((int) $activeOpname > 0) {
            $blockers[] = 'one or more stock opname sessions are still active (OPEN/FINALIZED)';
        }

        $futureTx = $pdo->prepare(
            "SELECT COUNT(*) FROM inventory_transactions WHERE status = 'POSTED' AND transaction_date > :end"
        );
        $futureTx->execute(['end' => $periodEnd . ' 23:59:59']);
        if ((int) $futureTx->fetchColumn() > 0) {
            $blockers[] = 'transactions already exist dated after period_end — close periods in order before later data is posted';
        }

        return $blockers;
    }

    private static function sumLines(PDO $pdo, string $transactionType, string $periodStart, string $periodEnd): float
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(ABS(l.subtotal)), 0) FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             WHERE t.transaction_type = :type AND t.status = 'POSTED'
               AND t.transaction_date BETWEEN :start AND :end"
        );
        $stmt->execute(['type' => $transactionType, 'start' => $periodStart, 'end' => $periodEnd . ' 23:59:59']);
        return round((float) $stmt->fetchColumn(), 4);
    }

    private static function shrinkageTotal(PDO $pdo, string $periodStart, string $periodEnd): float
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(ABS(sa.qty_base_delta) * sa.unit_cost_base), 0)
             FROM stock_adjustments sa
             JOIN inventory_transactions t ON t.id = sa.transaction_id
             WHERE sa.adjustment_type IN ('DAMAGE','EXPIRED','LOSS') AND sa.qty_base_delta < 0
               AND t.transaction_date BETWEEN :start AND :end"
        );
        $stmt->execute(['start' => $periodStart, 'end' => $periodEnd . ' 23:59:59']);
        return round((float) $stmt->fetchColumn(), 4);
    }
}
