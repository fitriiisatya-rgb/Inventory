<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * PHASE G25 — post-go-live monitoring. Read-only; never writes, never
 * mutates state. Every figure here is a live query against the same
 * tables the rest of the app reads — no separate "health" data source to
 * drift out of sync.
 */
final class SystemHealthService
{
    public static function check(PDO $pdo): array
    {
        $dbOk = true;
        $dbError = null;
        try {
            $pdo->query('SELECT 1')->fetchColumn();
        } catch (Throwable $e) {
            $dbOk = false;
            $dbError = $e->getMessage();
        }

        $lastTransaction = $pdo->query(
            "SELECT id, transaction_type, transaction_date, created_at FROM inventory_transactions
             WHERE status = 'POSTED' ORDER BY created_at DESC, id DESC LIMIT 1"
        )->fetch();

        $priceAnomalyCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM inventory_transaction_lines WHERE is_price_anomaly = 1'
        )->fetchColumn();

        $pendingTransferCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM warehouse_transfers WHERE status = 'PENDING'"
        )->fetchColumn();

        $activeOpnameCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM stock_opname_sessions WHERE status IN ('OPEN','FINALIZED')"
        )->fetchColumn();

        $negativeStockCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT item_id, warehouse_id FROM inventory_batches
                GROUP BY item_id, warehouse_id HAVING SUM(qty_base) < 0
             ) t'
        )->fetchColumn();

        $zeroCostBatchCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM inventory_batches WHERE qty_base > 0 AND unit_cost_base = 0'
        )->fetchColumn();

        return [
            'db_connection' => ['ok' => $dbOk, 'error' => $dbError],
            'last_successful_transaction' => $lastTransaction ?: null,
            // Not tracked: this codebase has no request-level access log/table
            // to count failures against. Documented as a known gap rather
            // than a fabricated number — see docs/PHASE_G25_HEALTH_ENDPOINT.md.
            'failed_requests_today' => null,
            'price_anomaly_count' => $priceAnomalyCount,
            'pending_transfer_count' => $pendingTransferCount,
            'active_opname_count' => $activeOpnameCount,
            'negative_stock_count' => $negativeStockCount,
            'zero_cost_batch_count' => $zeroCostBatchCount,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }
}
