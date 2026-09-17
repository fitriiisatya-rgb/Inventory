<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE G-DATA 2 Section 11 — read/report access to
 * movement_reconciliation_reviews (schema.sql Section 5A). Deliberately
 * has no "fix" method: the 8 historical movement-reconciliation rows are
 * evidence, and the owner's final verified stock is always authoritative.
 * Nothing here ever writes qty_base/unit_cost_base into a stock_opening_line
 * to force historical arithmetic to match.
 */
final class MovementReconciliationReviewService
{
    public static function list(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM movement_reconciliation_reviews ORDER BY sku, warehouse_code')->fetchAll();
    }

    /**
     * Records the owner's verified final opening figure against a
     * previously-seeded historical row and computes the difference. Never
     * touches stock_opening_lines / inventory_batches — this is a
     * side-by-side comparison record only.
     */
    public static function recordVerifiedFinal(PDO $pdo, int $id, float $verifiedFinalOpening, ?string $notes = null): array
    {
        $stmt = $pdo->prepare('SELECT * FROM movement_reconciliation_reviews WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new NotFoundException('movement reconciliation review row not found');
        }

        $historicalEnding = $row['historical_calculated_ending'] !== null ? (float) $row['historical_calculated_ending'] : null;
        $difference = $historicalEnding !== null ? round($verifiedFinalOpening - $historicalEnding, 6) : null;
        $status = $difference === null ? 'PENDING_FINAL_STOCK' : (abs($difference) < 0.000001 ? 'MATCHES' : 'DIFFERS');

        $pdo->prepare(
            'UPDATE movement_reconciliation_reviews
             SET verified_final_opening = :verified, difference = :diff, status = :status, notes = COALESCE(:notes, notes), updated_at = :now
             WHERE id = :id'
        )->execute([
            'verified' => $verifiedFinalOpening, 'diff' => $difference, 'status' => $status,
            'notes' => $notes, 'now' => date('Y-m-d H:i:s'), 'id' => $id,
        ]);

        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }
}
