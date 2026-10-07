<?php
declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/InventoryEffectiveDateService.php';

use PDO;

/**
 * "Pergerakan Stok Harian" (redesign) — item-level, qty + value, built ONLY on the existing ledger
 * (inventory_transactions / inventory_transaction_lines / fifo_allocations / stock_adjustments), never on today's
 * stock copied backwards, never on item_price_history.
 *
 * It reuses — never re-derives — the proven primitives of InventoryHppReportService / InventoryMovementReportService:
 *   SIGNED_VALUE_SQL (the one canonical signed value per line), cutoverContext() (go-live clamping),
 *   signedValueBefore() (boundary-inclusive opening anchor) and the SAME status / inventory_effect filters
 *   (status IN ('POSTED','VOID') AND inventory_effect = 1: a voided entry and its REVERSAL both count, each on its own date).
 *
 * Report buckets (transaction_type -> bucket), identical to the existing report's semantics:
 *   Barang Masuk   IN (status POSTED only)                      [+ TRANSFER_IN when ONE warehouse is selected]
 *   Barang Keluar  OUT (POSTED or VOID original)                [+ TRANSFER_OUT when ONE warehouse is selected]
 *   Adjustment / Pergerakan Lain (signed) = every other line:
 *       ADJUSTMENT (incl. posted Stock Opname corrections), a mid-period OPENING, REVERSAL, PRODUCTION_IN/OUT,
 *       the original entry of a voided IN, and — at company scope only — the NET of internal transfers
 *       (TRANSFER_IN - TRANSFER_OUT: 0 once every transfer is received, non-zero = goods in transit at the boundary).
 *   The company-wide Masuk/Keluar therefore never contain an internal transfer (no double counting).
 *
 * Identity per day and scope (computed from named buckets, never forced):
 *   Saldo Awal + Masuk - Keluar + Adjustment/Lain = Saldo Akhir
 * and Saldo Akhir is cross-checked against an INDEPENDENT ledger sum (a per-date SUM of SIGNED_VALUE_SQL seeded by
 * InventoryHppReportService::signedValueBefore()); any difference is returned in `reconciliation.issues` — the UI shows a
 * warning, the report never hides it. Qty uses the item's own base unit; quantities of different units are NEVER added
 * into one figure (only grouped BY UNIT).
 */
final class MovementDailyReportService
{
    public const MAX_DAYS = 400;
    public const EPS = 0.01;      // Rupiah tolerance (DECIMAL(20,4) rounding over many lines)
    public const EPS_QTY = 0.0005;

    /** signed base qty — same sign convention as SIGNED_VALUE_SQL (see InventoryHppReportService docblock) */
    public const SIGNED_QTY_SQL = "CASE
        WHEN t.transaction_type IN ('IN','OPENING','TRANSFER_IN','PRODUCTION_OUT') THEN l.base_qty
        WHEN t.transaction_type IN ('OUT','TRANSFER_OUT','PRODUCTION_IN') THEN -ABS(l.base_qty)
        ELSE l.base_qty
    END";

    private const TYPE_LABELS = [
        'IN' => 'Stock IN / Pembelian', 'OUT' => 'Stock OUT', 'TRANSFER_IN' => 'Transfer IN', 'TRANSFER_OUT' => 'Transfer OUT',
        'ADJUSTMENT' => 'Adjustment', 'OPNAME' => 'Stock Opname', 'OPENING' => 'Saldo Awal (Opening)', 'REVERSAL' => 'Pembalikan (Void / Reversal)',
        'PRODUCTION_IN' => 'Produksi (bahan keluar)', 'PRODUCTION_OUT' => 'Produksi (hasil masuk)',
    ];

    // ------------------------------------------------------------------ filters
    /**
     * @return array{join:string, where:list<string>, bind:array<string,mixed>}
     * Item filters (category / free text / exact item) on alias `i`; warehouse on `l.warehouse_id`.
     */
    private static function scope(?int $warehouseId, ?int $categoryId, ?string $q, ?int $itemId): array
    {
        $where = [];
        $bind = [];
        if ($warehouseId !== null) {
            $where[] = 'l.warehouse_id = :f_wh';
            $bind['f_wh'] = $warehouseId;
        }
        if ($categoryId !== null) {
            $where[] = 'i.category_id = :f_cat';
            $bind['f_cat'] = $categoryId;
        }
        if ($itemId !== null) {
            $where[] = 'i.id = :f_item';
            $bind['f_item'] = $itemId;
        }
        if ($q !== null && trim($q) !== '') {
            $where[] = '(i.sku LIKE :f_q1 OR i.name LIKE :f_q2)';
            $like = '%' . trim($q) . '%';
            $bind['f_q1'] = $like;
            $bind['f_q2'] = $like;
        }
        return ['join' => 'JOIN items i ON i.id = l.item_id', 'where' => $where, 'bind' => $bind];
    }

    private static function nextDay(string $d): string
    {
        return date('Y-m-d', strtotime($d . ' +1 day'));
    }

    // ------------------------------------------------------------------ core data
    /**
     * Opening position per item strictly BEFORE $start (plus a boundary-exact OPENING row, exactly like
     * InventoryHppReportService::signedValueBefore(..., true)).
     * @return array<int,array{qty:float,value:float}>
     */
    private static function openingByItem(PDO $pdo, string $start, array $sc): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        $where = array_merge(
            ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1',
             "({$td} < :o_before OR (t.transaction_type = 'OPENING' AND {$td} = :o_boundary))"],
            $sc['where']
        );
        $bind = array_merge(['o_before' => $start . ' 00:00:00', 'o_boundary' => $start . ' 00:00:00'], $sc['bind']);
        $stmt = $pdo->prepare(
            'SELECT l.item_id, SUM(' . self::SIGNED_QTY_SQL . ') AS q, SUM(' . InventoryHppReportService::SIGNED_VALUE_SQL . ") AS v
             FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id {$sc['join']}
             WHERE " . implode(' AND ', $where) . ' GROUP BY l.item_id'
        );
        $stmt->execute($bind);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['item_id']] = ['qty' => (float) $r['q'], 'value' => (float) $r['v']];
        }
        return $out;
    }

    /**
     * Raw per-(date,item) buckets for [start, end], value AND qty, from the ledger lines.
     * @return array<string,array<int,array<string,float>>> [date][item_id] => buckets
     */
    private static function grouped(PDO $pdo, string $start, string $end, array $sc): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        $sv = InventoryHppReportService::SIGNED_VALUE_SQL;
        $sq = self::SIGNED_QTY_SQL;
        $where = array_merge(
            ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', "{$td} >= :g_start", "{$td} < :g_end",
             "NOT (t.transaction_type = 'OPENING' AND {$td} = :g_boundary)"],
            $sc['where']
        );
        $bind = array_merge(['g_start' => $start . ' 00:00:00', 'g_end' => self::nextDay($end) . ' 00:00:00', 'g_boundary' => $start . ' 00:00:00'], $sc['bind']);
        $stmt = $pdo->prepare(
            "SELECT DATE({$td}) AS d, l.item_id,
                SUM(CASE WHEN t.transaction_type = 'IN' AND t.status = 'POSTED' THEN l.subtotal ELSE 0 END) AS v_ext,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_IN' THEN l.subtotal ELSE 0 END) AS v_tin,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_OUT' THEN ABS(l.subtotal) ELSE 0 END) AS v_tout,
                SUM(CASE WHEN t.transaction_type = 'OUT' THEN ABS(l.subtotal) ELSE 0 END) AS v_out,
                SUM(CASE WHEN t.transaction_type = 'ADJUSTMENT' AND l.subtotal > 0 THEN l.subtotal ELSE 0 END) AS v_adjp,
                SUM(CASE WHEN t.transaction_type = 'ADJUSTMENT' AND l.subtotal < 0 THEN -l.subtotal ELSE 0 END) AS v_adjn,
                SUM(CASE WHEN t.transaction_type = 'OPENING' THEN {$sv} ELSE 0 END) AS v_open,
                SUM(CASE WHEN {$sv} > 0 THEN {$sv} ELSE 0 END) AS v_intot,
                SUM(CASE WHEN {$sv} < 0 THEN -({$sv}) ELSE 0 END) AS v_outtot,
                SUM(CASE WHEN t.transaction_type = 'IN' AND t.status = 'POSTED' THEN ABS(l.base_qty) ELSE 0 END) AS q_ext,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_IN' THEN ABS(l.base_qty) ELSE 0 END) AS q_tin,
                SUM(CASE WHEN t.transaction_type = 'TRANSFER_OUT' THEN ABS(l.base_qty) ELSE 0 END) AS q_tout,
                SUM(CASE WHEN t.transaction_type = 'OUT' THEN ABS(l.base_qty) ELSE 0 END) AS q_out,
                SUM(CASE WHEN t.transaction_type = 'ADJUSTMENT' AND l.base_qty > 0 THEN l.base_qty ELSE 0 END) AS q_adjp,
                SUM(CASE WHEN t.transaction_type = 'ADJUSTMENT' AND l.base_qty < 0 THEN -l.base_qty ELSE 0 END) AS q_adjn,
                SUM(CASE WHEN t.transaction_type = 'OPENING' THEN {$sq} ELSE 0 END) AS q_open,
                SUM(CASE WHEN {$sq} > 0 THEN {$sq} ELSE 0 END) AS q_intot,
                SUM(CASE WHEN {$sq} < 0 THEN -({$sq}) ELSE 0 END) AS q_outtot,
                COUNT(DISTINCT t.id) AS tx_count
             FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id {$sc['join']}
             WHERE " . implode(' AND ', $where) . " GROUP BY DATE({$td}), l.item_id"
        );
        $stmt->execute($bind);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $row = [];
            foreach ($r as $k => $v) {
                if ($k !== 'd' && $k !== 'item_id') {
                    $row[$k] = (float) $v;
                }
            }
            $out[$r['d']][(int) $r['item_id']] = $row;
        }
        return $out;
    }

    /**
     * One side (value or qty) of a (date,item) row -> the report columns. Pure algebra on the named buckets.
     * @param array<string,float> $b prefix-stripped bucket set: ext,tin,tout,out,adjp,adjn,open,intot,outtot
     * @return array<string,float>
     */
    private static function derive(array $b, ?int $warehouseId): array
    {
        $openIn = max(0.0, $b['open']);
        $openOut = max(0.0, -$b['open']);
        // residual of the signed in/out totals after every named bucket — REVERSAL, production, the original of a voided IN ...
        $otherIn = $b['intot'] - $b['ext'] - $b['adjp'] - $b['tin'] - $openIn;
        $otherOut = $b['outtot'] - $b['out'] - $b['adjn'] - $b['tout'] - $openOut;
        $masukTransfer = $warehouseId !== null ? $b['tin'] : 0.0;
        $keluarTransfer = $warehouseId !== null ? $b['tout'] : 0.0;
        $elimination = $warehouseId === null ? ($b['tin'] - $b['tout']) : 0.0; // company scope: internal transfers net out (in-transit only)
        $masuk = $b['ext'] + $masukTransfer;
        $keluar = $b['out'] + $keluarTransfer;
        $adjustment = $b['adjp'] - $b['adjn'];
        $other = $otherIn - $otherOut + $openIn - $openOut + $elimination;
        return [
            'masuk' => $masuk, 'keluar' => $keluar, 'adjustment' => $adjustment, 'other' => $other, 'lain' => $adjustment + $other,
            'net' => $b['intot'] - $b['outtot'],
            'masuk_purchase' => $b['ext'], 'masuk_transfer' => $masukTransfer, 'keluar_usage' => $b['out'], 'keluar_transfer' => $keluarTransfer,
            'adj_pos' => $b['adjp'], 'adj_neg' => $b['adjn'], 'other_in' => $otherIn, 'other_out' => $otherOut,
            'opening_in' => $openIn - $openOut, 'transfer_elimination' => $elimination,
        ];
    }

    /** @return array{0:array<string,float>,1:array<string,float>} [value buckets, qty buckets] from a raw grouped row */
    private static function split(array $raw): array
    {
        $v = ['ext' => $raw['v_ext'], 'tin' => $raw['v_tin'], 'tout' => $raw['v_tout'], 'out' => $raw['v_out'], 'adjp' => $raw['v_adjp'], 'adjn' => $raw['v_adjn'], 'open' => $raw['v_open'], 'intot' => $raw['v_intot'], 'outtot' => $raw['v_outtot']];
        $q = ['ext' => $raw['q_ext'], 'tin' => $raw['q_tin'], 'tout' => $raw['q_tout'], 'out' => $raw['q_out'], 'adjp' => $raw['q_adjp'], 'adjn' => $raw['q_adjn'], 'open' => $raw['q_open'], 'intot' => $raw['q_intot'], 'outtot' => $raw['q_outtot']];
        return [$v, $q];
    }

    /** INDEPENDENT closing per day: per-date SUM(SIGNED_VALUE_SQL) seeded by the existing signedValueBefore(). @return array<string,float> */
    private static function directClosings(PDO $pdo, string $start, string $end, ?int $warehouseId, ?int $categoryId, ?string $q, ?int $itemId, array $sc): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        // opening anchor via the EXISTING engine (item filter clauses of the HPP service), not this class's own SQL
        $anchor = 0.0;
        $joinHpp = '';
        $whereHpp = [];
        $bindHpp = [];
        if ($categoryId !== null || ($q !== null && trim($q) !== '') || $itemId !== null) {
            [$joinHpp, $whereHpp, $bindHpp] = InventoryHppReportService::itemFilterClauses($categoryId, $q);
            if ($itemId !== null) {
                if ($joinHpp === '') { $joinHpp = 'JOIN items fi ON fi.id = l.item_id'; }
                $whereHpp[] = 'fi.id = :hpp_item';
                $bindHpp['hpp_item'] = $itemId;
            }
        }
        $anchor = InventoryHppReportService::signedValueBefore($pdo, $start, $warehouseId, $joinHpp, $whereHpp, $bindHpp, true);
        $where = array_merge(
            ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', "{$td} >= :d_start", "{$td} < :d_end",
             "NOT (t.transaction_type = 'OPENING' AND {$td} = :d_boundary)"],
            $sc['where']
        );
        $bind = array_merge(['d_start' => $start . ' 00:00:00', 'd_end' => self::nextDay($end) . ' 00:00:00', 'd_boundary' => $start . ' 00:00:00'], $sc['bind']);
        $stmt = $pdo->prepare(
            "SELECT DATE({$td}) AS d, SUM(" . InventoryHppReportService::SIGNED_VALUE_SQL . ") AS v
             FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id {$sc['join']}
             WHERE " . implode(' AND ', $where) . " GROUP BY DATE({$td})"
        );
        $stmt->execute($bind);
        $byDay = [];
        foreach ($stmt->fetchAll() as $r) {
            $byDay[$r['d']] = (float) $r['v'];
        }
        $out = [];
        $run = $anchor;
        for ($d = $start; $d <= $end; $d = self::nextDay($d)) {
            $run += $byDay[$d] ?? 0.0;
            $out[$d] = $run;
        }
        return $out;
    }

    /** distinct transaction counts per date by bucket (company/warehouse aware). @return array<string,array<string,int>> */
    private static function txCounts(PDO $pdo, string $start, string $end, ?int $warehouseId, array $sc): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        $where = array_merge(
            ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', "{$td} >= :c_start", "{$td} < :c_end",
             "NOT (t.transaction_type = 'OPENING' AND {$td} = :c_boundary)"],
            $sc['where']
        );
        $bind = array_merge(['c_start' => $start . ' 00:00:00', 'c_end' => self::nextDay($end) . ' 00:00:00', 'c_boundary' => $start . ' 00:00:00'], $sc['bind']);
        $masuk = $warehouseId !== null ? "((t.transaction_type = 'IN' AND t.status = 'POSTED') OR t.transaction_type = 'TRANSFER_IN')" : "(t.transaction_type = 'IN' AND t.status = 'POSTED')";
        $keluar = $warehouseId !== null ? "t.transaction_type IN ('OUT','TRANSFER_OUT')" : "t.transaction_type = 'OUT'";
        $stmt = $pdo->prepare(
            "SELECT DATE({$td}) AS d, COUNT(DISTINCT t.id) AS tx_total,
                    COUNT(DISTINCT CASE WHEN {$masuk} THEN t.id END) AS tx_in,
                    COUNT(DISTINCT CASE WHEN {$keluar} THEN t.id END) AS tx_out,
                    COUNT(DISTINCT CASE WHEN NOT ({$masuk}) AND NOT ({$keluar}) THEN t.id END) AS tx_other
             FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id {$sc['join']}
             WHERE " . implode(' AND ', $where) . " GROUP BY DATE({$td})"
        );
        $stmt->execute($bind);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['d']] = ['tx_total' => (int) $r['tx_total'], 'tx_in' => (int) $r['tx_in'], 'tx_out' => (int) $r['tx_out'], 'tx_other' => (int) $r['tx_other']];
        }
        return $out;
    }

    /** @return array<int,array{id:int,sku:string,name:string,category:?string,unit:string}> */
    private static function itemMeta(PDO $pdo, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $stmt = $pdo->query(
                "SELECT i.id, i.sku, i.name, c.name AS category, u.code AS unit FROM items i
                 JOIN units u ON u.id = i.base_unit_id LEFT JOIN categories c ON c.id = i.category_id WHERE i.id IN ({$in})"
            );
            foreach ($stmt->fetchAll() as $r) {
                $out[(int) $r['id']] = ['id' => (int) $r['id'], 'sku' => $r['sku'], 'name' => $r['name'], 'category' => $r['category'], 'unit' => $r['unit']];
            }
        }
        return $out;
    }

    /** @return array{id:int,sku:string,name:string,unit:string}|null the single item the filters resolve to (for the qty chart) */
    private static function singleItem(PDO $pdo, ?int $categoryId, ?string $q, ?int $itemId): ?array
    {
        if ($categoryId === null && ($q === null || trim($q) === '') && $itemId === null) {
            return null;
        }
        $where = ['1=1'];
        $bind = [];
        if ($categoryId !== null) { $where[] = 'i.category_id = :s_cat'; $bind['s_cat'] = $categoryId; }
        if ($itemId !== null) { $where[] = 'i.id = :s_item'; $bind['s_item'] = $itemId; }
        if ($q !== null && trim($q) !== '') { $where[] = '(i.sku LIKE :s_q1 OR i.name LIKE :s_q2)'; $bind['s_q1'] = $bind['s_q2'] = '%' . trim($q) . '%'; }
        $stmt = $pdo->prepare('SELECT i.id, i.sku, i.name, u.code AS unit FROM items i JOIN units u ON u.id = i.base_unit_id WHERE ' . implode(' AND ', $where) . ' LIMIT 2');
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();
        return count($rows) === 1 ? ['id' => (int) $rows[0]['id'], 'sku' => $rows[0]['sku'], 'name' => $rows[0]['name'], 'unit' => $rows[0]['unit']] : null;
    }

    // ------------------------------------------------------------------ public API
    /**
     * Period overview: daily rows (nominal + counts + qty-by-unit totals), KPI totals, reconciliation.
     *
     * @return array<string,mixed>
     */
    public static function overview(PDO $pdo, string $startDate, string $endDate, ?int $warehouseId, ?int $categoryId = null, ?string $q = null, ?int $itemId = null): array
    {
        $days = (int) ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        if ($days > self::MAX_DAYS) {
            throw new ValidationException(['Rentang tanggal maksimal ' . self::MAX_DAYS . ' hari.']);
        }
        $cutover = InventoryHppReportService::cutoverContext($pdo, $startDate, $endDate);
        $effStart = $cutover['effective_start_date'];
        $sc = self::scope($warehouseId, $categoryId, $q, $itemId);
        $single = self::singleItem($pdo, $categoryId, $q, $itemId);
        $live = !$cutover['is_pre_go_live_period'];

        $balance = $live ? self::openingByItem($pdo, $effStart, $sc) : [];
        $grouped = $live ? self::grouped($pdo, $effStart, $endDate, $sc) : [];
        $direct = $live ? self::directClosings($pdo, $effStart, $endDate, $warehouseId, $categoryId, $q, $itemId, $sc) : [];
        $tx = $live ? self::txCounts($pdo, $effStart, $endDate, $warehouseId, $sc) : [];
        $touched = array_keys($balance);
        foreach ($grouped as $perItem) { foreach (array_keys($perItem) as $iid) { $touched[$iid] = $iid; } }
        $meta = self::itemMeta($pdo, array_unique(array_map('intval', $touched)));

        $totOpenV = 0.0;
        foreach ($balance as $b) { $totOpenV += $b['value']; }
        $rows = [];
        $issues = [];
        $period = ['masuk' => 0.0, 'keluar' => 0.0, 'other' => 0.0, 'adjustment' => 0.0, 'sku_in' => [], 'sku_out' => [], 'sku_adj' => []];
        $qtyUnits = ['opening' => [], 'masuk' => [], 'keluar' => [], 'lain' => [], 'closing' => []];
        $periodOpening = null;
        $periodOpeningSku = 0;
        $prevClosing = null;
        for ($d = $startDate; $d <= $endDate; $d = self::nextDay($d)) {
            if ($d < $effStart) {
                $rows[] = ['date' => $d, 'is_pre_go_live' => true, 'nominal' => ['opening' => 0.0, 'masuk' => 0.0, 'keluar' => 0.0, 'adjustment' => 0.0, 'other' => 0.0, 'lain' => 0.0, 'closing' => 0.0],
                    'counts' => ['sku_opening' => 0, 'sku_closing' => 0, 'sku_moved' => 0, 'sku_in' => 0, 'sku_out' => 0, 'tx_total' => 0, 'tx_in' => 0, 'tx_out' => 0, 'tx_other' => 0], 'buckets' => [], 'qty' => null, 'reconciliation' => ['closing_direct' => 0.0, 'diff' => 0.0, 'ok' => true]];
                continue;
            }
            $openV = array_sum(array_column($balance, 'value'));
            $skuOpen = 0;
            foreach ($balance as $b) { if ($b['qty'] > self::EPS_QTY) { $skuOpen++; } }
            if ($periodOpening === null) {
                $periodOpening = $openV;
                $periodOpeningSku = $skuOpen;
                foreach ($balance as $iid => $b) {
                    if (isset($meta[$iid]) && abs($b['qty']) > self::EPS_QTY) {
                        $qtyUnits['opening'][$meta[$iid]['unit']] = ($qtyUnits['opening'][$meta[$iid]['unit']] ?? 0.0) + $b['qty'];
                    }
                }
            }
            $day = ['masuk' => 0.0, 'keluar' => 0.0, 'adjustment' => 0.0, 'other' => 0.0, 'net' => 0.0];
            $bk = ['masuk_purchase' => 0.0, 'masuk_transfer' => 0.0, 'keluar_usage' => 0.0, 'keluar_transfer' => 0.0, 'adj_pos' => 0.0, 'adj_neg' => 0.0, 'other_in' => 0.0, 'other_out' => 0.0, 'opening_in' => 0.0, 'transfer_elimination' => 0.0];
            $moved = $sIn = $sOut = 0;
            $single_q = ['masuk' => 0.0, 'keluar' => 0.0, 'lain' => 0.0];
            foreach ($grouped[$d] ?? [] as $iid => $raw) {
                [$vb, $qb] = self::split($raw);
                $dv = self::derive($vb, $warehouseId);
                $dq = self::derive($qb, $warehouseId);
                foreach (['masuk', 'keluar', 'adjustment', 'other', 'net'] as $k) { $day[$k] += $dv[$k]; }
                foreach ($bk as $k => $_) { $bk[$k] += $dv[$k]; }
                if (abs($dv['masuk']) > 0 || abs($dq['masuk']) > self::EPS_QTY) { $sIn++; $period['sku_in'][$iid] = true; }
                if (abs($dv['keluar']) > 0 || abs($dq['keluar']) > self::EPS_QTY) { $sOut++; $period['sku_out'][$iid] = true; }
                if (abs($dv['lain']) > 0 || abs($dq['lain']) > self::EPS_QTY) { $period['sku_adj'][$iid] = true; }
                if (abs($dv['net']) > 0 || abs($dq['net']) > self::EPS_QTY || $dv['masuk'] != 0 || $dv['keluar'] != 0) { $moved++; }
                $balance[$iid] = ['qty' => ($balance[$iid]['qty'] ?? 0.0) + $dq['net'], 'value' => ($balance[$iid]['value'] ?? 0.0) + $dv['net']];
                if ($single !== null) { $single_q['masuk'] += $dq['masuk']; $single_q['keluar'] += $dq['keluar']; $single_q['lain'] += $dq['lain']; }
                if (isset($meta[$iid])) {
                    $u = $meta[$iid]['unit'];
                    $qtyUnits['masuk'][$u] = ($qtyUnits['masuk'][$u] ?? 0.0) + $dq['masuk'];
                    $qtyUnits['keluar'][$u] = ($qtyUnits['keluar'][$u] ?? 0.0) + $dq['keluar'];
                    $qtyUnits['lain'][$u] = ($qtyUnits['lain'][$u] ?? 0.0) + $dq['lain'];
                }
            }
            $closeV = array_sum(array_column($balance, 'value'));
            $skuClose = 0;
            foreach ($balance as $b) { if ($b['qty'] > self::EPS_QTY) { $skuClose++; } }
            $lain = $day['adjustment'] + $day['other'];
            $expected = $openV + $day['masuk'] - $day['keluar'] + $lain;
            $diffIdentity = $closeV - $expected;
            $diffDirect = $closeV - ($direct[$d] ?? 0.0);
            $ok = abs($diffIdentity) <= self::EPS && abs($diffDirect) <= self::EPS;
            if (abs($diffIdentity) > self::EPS) { $issues[] = ['date' => $d, 'type' => 'IDENTITY', 'difference' => round($diffIdentity, 4), 'message' => 'Saldo Awal + Masuk - Keluar ± Lain tidak sama dengan Saldo Akhir']; }
            if (abs($diffDirect) > self::EPS) { $issues[] = ['date' => $d, 'type' => 'LEDGER', 'difference' => round($diffDirect, 4), 'message' => 'Saldo Akhir per barang berbeda dari jumlah langsung ledger']; }
            if ($prevClosing !== null && abs($openV - $prevClosing) > self::EPS) { $issues[] = ['date' => $d, 'type' => 'CARRY', 'difference' => round($openV - $prevClosing, 4), 'message' => 'Saldo Awal hari ini berbeda dari Saldo Akhir hari sebelumnya']; }
            $prevClosing = $closeV;
            $c = $tx[$d] ?? ['tx_total' => 0, 'tx_in' => 0, 'tx_out' => 0, 'tx_other' => 0];
            $qtyRow = null;
            if ($single !== null) {
                $iq = $balance[$single['id']]['qty'] ?? 0.0;
                $qtyRow = ['unit' => $single['unit'], 'opening' => round($iq - $single_q['masuk'] + $single_q['keluar'] - $single_q['lain'], 6), 'masuk' => round($single_q['masuk'], 6), 'keluar' => round($single_q['keluar'], 6), 'lain' => round($single_q['lain'], 6), 'closing' => round($iq, 6)];
            }
            $rows[] = [
                'date' => $d, 'is_pre_go_live' => false,
                'nominal' => ['opening' => round($openV, 4), 'masuk' => round($day['masuk'], 4), 'keluar' => round($day['keluar'], 4), 'adjustment' => round($day['adjustment'], 4), 'other' => round($day['other'], 4), 'lain' => round($lain, 4), 'closing' => round($closeV, 4)],
                'counts' => ['sku_opening' => $skuOpen, 'sku_closing' => $skuClose, 'sku_moved' => $moved, 'sku_in' => $sIn, 'sku_out' => $sOut] + $c,
                'buckets' => array_map(static fn ($v) => round($v, 4), $bk),
                'qty' => $qtyRow,
                'reconciliation' => ['closing_direct' => round($direct[$d] ?? 0.0, 4), 'diff' => round($diffDirect, 4), 'identity_diff' => round($diffIdentity, 4), 'ok' => $ok],
            ];
            $period['masuk'] += $day['masuk'];
            $period['keluar'] += $day['keluar'];
            $period['adjustment'] += $day['adjustment'];
            $period['other'] += $day['other'];
        }
        $lastLive = null;
        foreach (array_reverse($rows) as $r) { if (!$r['is_pre_go_live']) { $lastLive = $r; break; } }
        foreach ($balance as $iid => $b) {
            if (isset($meta[$iid]) && abs($b['qty']) > self::EPS_QTY) { $qtyUnits['closing'][$meta[$iid]['unit']] = ($qtyUnits['closing'][$meta[$iid]['unit']] ?? 0.0) + $b['qty']; }
        }
        $periodTx = $live ? self::periodTxCounts($pdo, $effStart, $endDate, $warehouseId, $sc) : ['tx_total' => 0, 'tx_in' => 0, 'tx_out' => 0, 'tx_other' => 0];
        $fmtUnits = static function (array $m): array {
            $out = [];
            foreach ($m as $u => $v) { if (abs($v) > self::EPS_QTY) { $out[] = ['unit' => (string) $u, 'qty' => round($v, 6)]; } }   // (string): a numeric unit code is an int array key
            usort($out, static fn ($a, $b) => strcmp((string) $a['unit'], (string) $b['unit']));
            return $out;
        };
        $totals = [
            'opening' => round((float) $periodOpening, 4),
            'masuk' => round($period['masuk'], 4), 'keluar' => round($period['keluar'], 4),
            'adjustment' => round($period['adjustment'], 4), 'other' => round($period['other'], 4), 'lain' => round($period['adjustment'] + $period['other'], 4),
            'closing' => $lastLive ? $lastLive['nominal']['closing'] : 0.0,
            'sku_opening' => $periodOpeningSku, 'sku_closing' => $lastLive ? $lastLive['counts']['sku_closing'] : 0,
            'sku_in' => count($period['sku_in']), 'sku_out' => count($period['sku_out']), 'sku_adjustment' => count($period['sku_adj']),
            'tx_total' => $periodTx['tx_total'], 'tx_in' => $periodTx['tx_in'], 'tx_out' => $periodTx['tx_out'], 'tx_other' => $periodTx['tx_other'],
            'qty_by_unit' => array_map($fmtUnits, $qtyUnits),
        ];
        if ($periodOpening !== null) {
            $expectedClose = $totals['opening'] + $totals['masuk'] - $totals['keluar'] + $totals['lain'];
            if (abs($expectedClose - $totals['closing']) > self::EPS * max(1, $days)) {
                $issues[] = ['date' => null, 'type' => 'PERIOD', 'difference' => round($totals['closing'] - $expectedClose, 4), 'message' => 'Total periode: Saldo Awal + Masuk - Keluar ± Lain ≠ Saldo Akhir'];
            }
        }
        return [
            'period' => ['start_date' => $startDate, 'end_date' => $endDate, 'warehouse_id' => $warehouseId, 'category_id' => $categoryId, 'q' => $q, 'item_id' => $itemId],
            'cutover' => $cutover,
            'is_company_consolidated' => $warehouseId === null,
            'single_item' => $single,
            'rows' => $rows,
            'totals' => $totals,
            'reconciliation' => ['ok' => !$issues, 'tolerance' => self::EPS, 'issues' => $issues],
            'historical' => InventoryMovementReportService::dailyMovement($pdo, $startDate, $endDate, $warehouseId)['historical'],
        ];
    }

    /** @return array{tx_total:int,tx_in:int,tx_out:int,tx_other:int} */
    private static function periodTxCounts(PDO $pdo, string $start, string $end, ?int $warehouseId, array $sc): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        $where = array_merge(
            ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', "{$td} >= :p_start", "{$td} < :p_end",
             "NOT (t.transaction_type = 'OPENING' AND {$td} = :p_boundary)"],
            $sc['where']
        );
        $bind = array_merge(['p_start' => $start . ' 00:00:00', 'p_end' => self::nextDay($end) . ' 00:00:00', 'p_boundary' => $start . ' 00:00:00'], $sc['bind']);
        $masuk = $warehouseId !== null ? "((t.transaction_type = 'IN' AND t.status = 'POSTED') OR t.transaction_type = 'TRANSFER_IN')" : "(t.transaction_type = 'IN' AND t.status = 'POSTED')";
        $keluar = $warehouseId !== null ? "t.transaction_type IN ('OUT','TRANSFER_OUT')" : "t.transaction_type = 'OUT'";
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT t.id) AS tx_total, COUNT(DISTINCT CASE WHEN {$masuk} THEN t.id END) AS tx_in,
                    COUNT(DISTINCT CASE WHEN {$keluar} THEN t.id END) AS tx_out,
                    COUNT(DISTINCT CASE WHEN NOT ({$masuk}) AND NOT ({$keluar}) THEN t.id END) AS tx_other
             FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id {$sc['join']}
             WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($bind);
        $r = $stmt->fetch();
        return ['tx_total' => (int) $r['tx_total'], 'tx_in' => (int) $r['tx_in'], 'tx_out' => (int) $r['tx_out'], 'tx_other' => (int) $r['tx_other']];
    }

    /**
     * "Rincian Per Barang" for one date: every item with stock or movement that day (filters applied), its
     * opening / masuk / keluar / adjustment / closing in qty AND value, in its OWN unit. Footer totals cover ALL matching rows.
     *
     * @param ?string $movement one of masuk|keluar|adjustment|transfer|null (rows with a non-zero amount in that bucket)
     * @return array<string,mixed>
     */
    public static function dayItems(PDO $pdo, string $date, ?int $warehouseId, ?int $categoryId, ?string $q, ?int $itemId, ?string $movement, string $sort, string $dir, int $page, int $perPage, bool $movedOnly = false): array
    {
        if ($movement !== null && !in_array($movement, ['masuk', 'keluar', 'adjustment', 'transfer'], true)) {
            throw new ValidationException(["movement must be masuk, keluar, adjustment or transfer"]);
        }
        $cutover = InventoryHppReportService::cutoverContext($pdo, $date, $date);
        if ($cutover['is_pre_go_live_period']) {
            return ['date' => $date, 'cutover' => $cutover, 'rows' => [], 'totals' => self::emptyTotals(), 'pagination' => self::pagination(0, $page, $perPage), 'is_company_consolidated' => $warehouseId === null];
        }
        $effStart = $cutover['effective_start_date']; // == $date
        $sc = self::scope($warehouseId, $categoryId, $q, $itemId);
        $opening = self::openingByItem($pdo, $effStart, $sc);
        $grouped = self::grouped($pdo, $effStart, $effStart, $sc);
        $perItem = $grouped[$date] ?? [];
        $ids = array_unique(array_merge(array_keys($opening), array_keys($perItem)));
        $meta = self::itemMeta($pdo, $ids);
        $rows = [];
        $tot = self::emptyTotals();
        foreach ($ids as $iid) {
            $iid = (int) $iid;
            if (!isset($meta[$iid])) { continue; }
            $o = $opening[$iid] ?? ['qty' => 0.0, 'value' => 0.0];
            $raw = $perItem[$iid] ?? null;
            if ($raw !== null) {
                [$vb, $qb] = self::split($raw);
                $dv = self::derive($vb, $warehouseId);
                $dq = self::derive($qb, $warehouseId);
                $txc = (int) $raw['tx_count'];
            } else {
                $z = array_fill_keys(['ext', 'tin', 'tout', 'out', 'adjp', 'adjn', 'open', 'intot', 'outtot'], 0.0);
                $dv = self::derive($z, $warehouseId);
                $dq = $dv;
                $txc = 0;
            }
            $closeQ = $o['qty'] + $dq['net'];
            $closeV = $o['value'] + $dv['net'];
            $hasStock = abs($o['qty']) > self::EPS_QTY || abs($closeQ) > self::EPS_QTY || abs($o['value']) > self::EPS || abs($closeV) > self::EPS;
            $hasMove = $raw !== null;
            if (!$hasStock && !$hasMove) { continue; }
            if ($movedOnly && !$hasMove) { continue; }
            if ($movement !== null) {
                $hit = match ($movement) {
                    'masuk' => abs($dv['masuk']) > 0 || abs($dq['masuk']) > self::EPS_QTY,
                    'keluar' => abs($dv['keluar']) > 0 || abs($dq['keluar']) > self::EPS_QTY,
                    'adjustment' => abs($dv['lain']) > 0 || abs($dq['lain']) > self::EPS_QTY,
                    'transfer' => abs($vb['tin'] ?? 0.0) > 0 || abs($vb['tout'] ?? 0.0) > 0 || abs($qb['tin'] ?? 0.0) > self::EPS_QTY || abs($qb['tout'] ?? 0.0) > self::EPS_QTY,
                };
                if (!$hit) { continue; }
            }
            $m = $meta[$iid];
            $row = [
                'item_id' => $iid, 'sku' => $m['sku'], 'name' => $m['name'], 'category' => $m['category'], 'unit' => $m['unit'],
                'opening_qty' => round($o['qty'], 6), 'opening_value' => round($o['value'], 4),
                'masuk_qty' => round($dq['masuk'], 6), 'masuk_value' => round($dv['masuk'], 4),
                'keluar_qty' => round($dq['keluar'], 6), 'keluar_value' => round($dv['keluar'], 4),
                'hpp_keluar_per_unit' => $dq['keluar'] > self::EPS_QTY ? round($dv['keluar'] / $dq['keluar'], 4) : null,
                'adjustment_qty' => round($dq['lain'], 6), 'adjustment_value' => round($dv['lain'], 4),
                'closing_qty' => round($closeQ, 6), 'closing_value' => round($closeV, 4),
                'tx_count' => $txc,
                'detail' => [
                    'masuk_purchase_qty' => round($dq['masuk_purchase'], 6), 'masuk_purchase_value' => round($dv['masuk_purchase'], 4),
                    'masuk_transfer_qty' => round($dq['masuk_transfer'], 6), 'masuk_transfer_value' => round($dv['masuk_transfer'], 4),
                    'keluar_usage_qty' => round($dq['keluar_usage'], 6), 'keluar_usage_value' => round($dv['keluar_usage'], 4),
                    'keluar_transfer_qty' => round($dq['keluar_transfer'], 6), 'keluar_transfer_value' => round($dv['keluar_transfer'], 4),
                    'adj_pos_value' => round($dv['adj_pos'], 4), 'adj_neg_value' => round($dv['adj_neg'], 4),
                    'other_value' => round($dv['other'], 4), 'transfer_elimination_value' => round($dv['transfer_elimination'], 4),
                ],
            ];
            $rows[] = $row;
            foreach (['opening_value', 'masuk_value', 'keluar_value', 'adjustment_value', 'closing_value'] as $k) { $tot[$k] += $row[$k]; }
            $tot['sku_count']++;
            if (abs($row['closing_qty']) > self::EPS_QTY && $row['closing_qty'] > 0) { $tot['sku_closing']++; }
            $tot['tx_count'] += $txc;
            foreach (['opening', 'masuk', 'keluar', 'adjustment', 'closing'] as $k) {
                $qk = $k . '_qty';
                $tot['qty_by_unit'][$k][$m['unit']] = ($tot['qty_by_unit'][$k][$m['unit']] ?? 0.0) + $row[$qk];
            }
        }
        foreach (['opening_value', 'masuk_value', 'keluar_value', 'adjustment_value', 'closing_value'] as $k) { $tot[$k] = round($tot[$k], 4); }
        foreach ($tot['qty_by_unit'] as $k => $units) {
            $list = [];
            foreach ($units as $u => $v) { if (abs($v) > self::EPS_QTY) { $list[] = ['unit' => (string) $u, 'qty' => round($v, 6)]; } }
            usort($list, static fn ($a, $b) => strcmp((string) $a['unit'], (string) $b['unit']));
            $tot['qty_by_unit'][$k] = $list;
        }
        $sortable = ['sku', 'name', 'category', 'opening_value', 'masuk_value', 'keluar_value', 'adjustment_value', 'closing_value', 'opening_qty', 'masuk_qty', 'keluar_qty', 'closing_qty', 'tx_count'];
        $sortKey = in_array($sort, $sortable, true) ? $sort : 'sku';
        $mul = strtolower($dir) === 'desc' ? -1 : 1;
        usort($rows, static function ($a, $b) use ($sortKey, $mul) {
            $x = $a[$sortKey]; $y = $b[$sortKey];
            $c = is_numeric($x) && is_numeric($y) ? ($x <=> $y) : strcasecmp((string) $x, (string) $y);
            return $c === 0 ? strcmp((string) $a['sku'], (string) $b['sku']) : $c * $mul;
        });
        $total = count($rows);
        return [
            'date' => $date, 'cutover' => $cutover, 'is_company_consolidated' => $warehouseId === null,
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'totals' => $tot, 'pagination' => self::pagination($total, $page, $perPage),
        ];
    }

    /** @return array<string,mixed> */
    private static function emptyTotals(): array
    {
        return ['opening_value' => 0.0, 'masuk_value' => 0.0, 'keluar_value' => 0.0, 'adjustment_value' => 0.0, 'closing_value' => 0.0, 'sku_count' => 0, 'sku_closing' => 0, 'tx_count' => 0,
            'qty_by_unit' => ['opening' => [], 'masuk' => [], 'keluar' => [], 'adjustment' => [], 'closing' => []]];
    }

    /** @return array{page:int,per_page:int,total:int,total_pages:int} */
    private static function pagination(int $total, int $page, int $perPage): array
    {
        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / max(1, $perPage)))];
    }

    /**
     * The real transaction trail of ONE item on ONE date, with the balance after every transaction.
     * Cost shown is the one the system recorded: purchase cost on IN, the FIFO cost (fifo_allocations) on OUT.
     *
     * @return array<string,mixed>
     */
    public static function itemTrail(PDO $pdo, string $date, int $itemId, ?int $warehouseId): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        $cutover = InventoryHppReportService::cutoverContext($pdo, $date, $date);
        $item = self::itemMeta($pdo, [$itemId])[$itemId] ?? null;
        if ($item === null) {
            throw new NotFoundException("item {$itemId}");
        }
        if ($cutover['is_pre_go_live_period']) {
            return ['date' => $date, 'item' => $item, 'cutover' => $cutover, 'opening' => ['qty' => 0.0, 'value' => 0.0], 'rows' => [], 'closing' => ['qty' => 0.0, 'value' => 0.0], 'totals' => ['masuk_qty' => 0.0, 'keluar_qty' => 0.0]];
        }
        $sc = self::scope($warehouseId, null, null, $itemId);
        $open = self::openingByItem($pdo, $date, $sc)[$itemId] ?? ['qty' => 0.0, 'value' => 0.0];
        $sv = InventoryHppReportService::SIGNED_VALUE_SQL;
        $sq = self::SIGNED_QTY_SQL;
        $where = array_merge(
            ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', "{$td} >= :t_start", "{$td} < :t_end",
             "NOT (t.transaction_type = 'OPENING' AND {$td} = :t_boundary)"],
            $sc['where']
        );
        $bind = array_merge(['t_start' => $date . ' 00:00:00', 't_end' => self::nextDay($date) . ' 00:00:00', 't_boundary' => $date . ' 00:00:00'], $sc['bind']);
        $stmt = $pdo->prepare(
            "SELECT t.id AS transaction_id, l.id AS line_id, t.transaction_type, t.status, t.reference_no, {$td} AS transaction_date, t.created_at,
                    w.code AS warehouse_code, w.name AS warehouse_name, l.warehouse_id,
                    l.base_qty, l.unit_cost_base, l.unit_price_input, l.subtotal, l.notes AS line_notes, t.void_reason,
                    {$sv} AS signed_value, {$sq} AS signed_qty,
                    (SELECT COALESCE(SUM(a.subtotal), 0) FROM fifo_allocations a WHERE a.transaction_line_id = l.id) AS fifo_value,
                    (SELECT COALESCE(SUM(a.qty_allocated), 0) FROM fifo_allocations a WHERE a.transaction_line_id = l.id) AS fifo_qty,
                    u.username AS user_name, u.full_name AS user_full_name,
                    sa.adjustment_type, sa.reason AS adjustment_reason, sa.reference_no AS adjustment_reference,
                    s.name AS supplier_name, bd.name AS bakery_name
             FROM inventory_transaction_lines l
             JOIN inventory_transactions t ON t.id = l.transaction_id
             {$sc['join']}
             JOIN warehouses w ON w.id = l.warehouse_id
             LEFT JOIN users u ON u.id = t.created_by
             LEFT JOIN stock_adjustments sa ON sa.transaction_id = t.id AND sa.item_id = l.item_id AND sa.warehouse_id = l.warehouse_id
             LEFT JOIN suppliers s ON s.id = t.supplier_id
             LEFT JOIN bakery_destinations bd ON bd.id = t.bakery_destination_id
             WHERE " . implode(' AND ', $where) . " ORDER BY {$td} ASC, t.id ASC, l.id ASC"
        );
        $stmt->execute($bind);
        $balQ = $open['qty'];
        $balV = $open['value'];
        $rows = [];
        $sumIn = $sumOut = 0.0;
        foreach ($stmt->fetchAll() as $r) {
            $type = $r['transaction_type'];
            $sQty = (float) $r['signed_qty'];
            $sVal = (float) $r['signed_value'];
            $balQ += $sQty;
            $balV += $sVal;
            $label = self::TYPE_LABELS[$type] ?? $type;
            if ($type === 'ADJUSTMENT' && $r['adjustment_type'] === 'OPNAME') { $label = 'Koreksi Stock Opname'; }
            elseif ($type === 'ADJUSTMENT' && $r['adjustment_type']) { $label = 'Adjustment — ' . $r['adjustment_type']; }
            if ($r['status'] === 'VOID') { $label .= ' (VOID)'; }
            $internal = $warehouseId === null && in_array($type, ['TRANSFER_IN', 'TRANSFER_OUT'], true);
            $bucket = match (true) {
                $type === 'IN' && $r['status'] === 'POSTED' => 'masuk',
                $type === 'TRANSFER_IN' => $warehouseId !== null ? 'masuk' : 'transfer_internal',
                $type === 'OUT' => 'keluar',
                $type === 'TRANSFER_OUT' => $warehouseId !== null ? 'keluar' : 'transfer_internal',
                default => 'adjustment',
            };
            $isIn = $sQty > 0;
            $note = trim(implode(' · ', array_filter([
                $r['adjustment_reason'], $r['line_notes'], $r['void_reason'],
                $r['supplier_name'] ? 'Supplier: ' . $r['supplier_name'] : null, $r['bakery_name'] ? 'Bakery: ' . $r['bakery_name'] : null,
            ])));
            $isOut = in_array($type, ['OUT', 'TRANSFER_OUT', 'PRODUCTION_IN'], true);
            $hppValue = $isOut ? ($r['fifo_qty'] > 0 ? (float) $r['fifo_value'] : abs((float) $r['subtotal'])) : null;
            $rows[] = [
                'transaction_id' => (int) $r['transaction_id'], 'line_id' => (int) $r['line_id'], 'timestamp' => $r['transaction_date'], 'posted_at' => $r['created_at'],
                'type' => $type, 'type_label' => $label, 'status' => $r['status'], 'bucket' => $bucket, 'internal_transfer' => $internal,
                'reference_no' => $r['reference_no'] ?: ($r['adjustment_reference'] ?: null),
                'warehouse_id' => (int) $r['warehouse_id'], 'warehouse_code' => $r['warehouse_code'], 'warehouse_name' => $r['warehouse_name'],
                'qty_in' => !$isOut && $isIn && $type !== 'ADJUSTMENT' ? round($sQty, 6) : null,
                'cost_in' => !$isOut && $isIn && $type !== 'ADJUSTMENT' ? round((float) $r['unit_cost_base'], 4) : null,
                'value_in' => !$isOut && $isIn && $type !== 'ADJUSTMENT' ? round($sVal, 4) : null,
                'qty_out' => $isOut ? round(abs($sQty), 6) : null,
                'hpp_out_per_unit' => $isOut && abs($sQty) > 0 ? round($hppValue / abs($sQty), 4) : null,
                'hpp_out' => $isOut ? round($hppValue, 4) : null,
                'fifo_allocated_value' => $isOut ? round((float) $r['fifo_value'], 4) : null,
                'adjustment_qty' => !$isOut && ($type === 'ADJUSTMENT' || !$isIn || !in_array($type, ['IN', 'OPENING', 'TRANSFER_IN', 'PRODUCTION_OUT'], true)) && $type !== 'IN' && $type !== 'TRANSFER_IN' ? round($sQty, 6) : null,
                'adjustment_value' => !$isOut && ($type === 'ADJUSTMENT' || !in_array($type, ['IN', 'OPENING', 'TRANSFER_IN', 'PRODUCTION_OUT'], true)) ? round($sVal, 4) : null,
                'balance_qty' => round($balQ, 6), 'balance_value' => round($balV, 4),
                'user' => $r['user_full_name'] ?: $r['user_name'], 'username' => $r['user_name'], 'note' => $note !== '' ? $note : null,
            ];
            if ($bucket === 'masuk') { $sumIn += abs($sQty); }
            if ($bucket === 'keluar') { $sumOut += abs($sQty); }
        }
        return [
            'date' => $date, 'item' => $item, 'cutover' => $cutover, 'is_company_consolidated' => $warehouseId === null,
            'opening' => ['qty' => round($open['qty'], 6), 'value' => round($open['value'], 4)],
            'rows' => $rows,
            'closing' => ['qty' => round($balQ, 6), 'value' => round($balV, 4)],
            'totals' => ['masuk_qty' => round($sumIn, 6), 'keluar_qty' => round($sumOut, 6)],
        ];
    }

    /**
     * KPI-card drill-down: the underlying ledger lines of one bucket over the whole period.
     *
     * @param string $bucket masuk|keluar|other
     * @return array<string,mixed>
     */
    public static function periodTransactions(PDO $pdo, string $start, string $end, ?int $warehouseId, ?int $categoryId, ?string $q, ?int $itemId, string $bucket, int $page, int $perPage): array
    {
        $td = InventoryEffectiveDateService::col($pdo);   // reporting date (effective-date override aware; plain transaction_date when no override exists)
        if (!in_array($bucket, ['masuk', 'keluar', 'other'], true)) {
            throw new ValidationException(['bucket must be masuk, keluar or other']);
        }
        $cutover = InventoryHppReportService::cutoverContext($pdo, $start, $end);
        if ($cutover['is_pre_go_live_period']) {
            return ['rows' => [], 'totals' => ['value' => 0.0], 'pagination' => self::pagination(0, $page, $perPage), 'cutover' => $cutover];
        }
        $effStart = $cutover['effective_start_date'];
        $sc = self::scope($warehouseId, $categoryId, $q, $itemId);
        $masuk = $warehouseId !== null ? "((t.transaction_type = 'IN' AND t.status = 'POSTED') OR t.transaction_type = 'TRANSFER_IN')" : "(t.transaction_type = 'IN' AND t.status = 'POSTED')";
        $keluar = $warehouseId !== null ? "t.transaction_type IN ('OUT','TRANSFER_OUT')" : "t.transaction_type = 'OUT'";
        $cond = match ($bucket) { 'masuk' => $masuk, 'keluar' => $keluar, 'other' => "NOT ({$masuk}) AND NOT ({$keluar})" };
        $where = array_merge(
            ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', "{$td} >= :k_start", "{$td} < :k_end",
             "NOT (t.transaction_type = 'OPENING' AND {$td} = :k_boundary)", $cond],
            $sc['where']
        );
        $bind = array_merge(['k_start' => $effStart . ' 00:00:00', 'k_end' => self::nextDay($end) . ' 00:00:00', 'k_boundary' => $effStart . ' 00:00:00'], $sc['bind']);
        $from = "FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id {$sc['join']} JOIN warehouses w ON w.id = l.warehouse_id WHERE " . implode(' AND ', $where);
        $sv = InventoryHppReportService::SIGNED_VALUE_SQL;
        $tot = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM({$sv}),0) AS v " . $from);
        $tot->execute($bind);
        $t = $tot->fetch();
        $stmt = $pdo->prepare(
            "SELECT t.id AS transaction_id, t.transaction_type, t.status, t.reference_no, {$td} AS transaction_date, w.code AS warehouse_code, i.sku, i.name AS item_name,
                    ABS(l.base_qty) AS qty, ABS({$sv}) AS value, {$sv} AS signed_value " . $from .
            " ORDER BY {$td} ASC, t.id ASC, l.id ASC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
        );
        $stmt->execute($bind);
        $rows = array_map(static fn ($r) => [
            'transaction_id' => (int) $r['transaction_id'], 'timestamp' => $r['transaction_date'], 'type' => $r['transaction_type'],
            'type_label' => (self::TYPE_LABELS[$r['transaction_type']] ?? $r['transaction_type']) . ($r['status'] === 'VOID' ? ' (VOID)' : ''),
            'reference_no' => $r['reference_no'], 'warehouse_code' => $r['warehouse_code'], 'sku' => $r['sku'], 'item_name' => $r['item_name'],
            'qty' => round((float) $r['qty'], 6), 'value' => round((float) $r['value'], 4), 'signed_value' => round((float) $r['signed_value'], 4),
        ], $stmt->fetchAll());
        return ['rows' => $rows, 'totals' => ['value' => round((float) $t['v'], 4), 'abs_value' => round(abs((float) $t['v']), 4)], 'pagination' => self::pagination((int) $t['n'], $page, $perPage), 'cutover' => $cutover];
    }

    /**
     * Rows for the detail export: one per (date, item) that had any movement, qty + value + unit.
     * @return list<array<string,mixed>>
     */
    public static function detailRows(PDO $pdo, string $start, string $end, ?int $warehouseId, ?int $categoryId, ?string $q, ?int $itemId): array
    {
        $cutover = InventoryHppReportService::cutoverContext($pdo, $start, $end);
        if ($cutover['is_pre_go_live_period']) {
            return [];
        }
        $effStart = $cutover['effective_start_date'];
        $sc = self::scope($warehouseId, $categoryId, $q, $itemId);
        $balance = self::openingByItem($pdo, $effStart, $sc);
        $grouped = self::grouped($pdo, $effStart, $end, $sc);
        $ids = $balance ? array_keys($balance) : [];
        foreach ($grouped as $per) { foreach (array_keys($per) as $iid) { $ids[] = $iid; } }
        $meta = self::itemMeta($pdo, array_unique($ids));
        $out = [];
        for ($d = $effStart; $d <= $end; $d = self::nextDay($d)) {
            foreach ($grouped[$d] ?? [] as $iid => $raw) {
                [$vb, $qb] = self::split($raw);
                $dv = self::derive($vb, $warehouseId);
                $dq = self::derive($qb, $warehouseId);
                $o = $balance[$iid] ?? ['qty' => 0.0, 'value' => 0.0];
                $closeQ = $o['qty'] + $dq['net'];
                $closeV = $o['value'] + $dv['net'];
                $m = $meta[$iid] ?? ['sku' => (string) $iid, 'name' => '?', 'category' => null, 'unit' => ''];
                $out[] = [
                    'date' => $d, 'sku' => $m['sku'], 'name' => $m['name'], 'category' => $m['category'], 'unit' => $m['unit'],
                    'opening_qty' => round($o['qty'], 6), 'opening_value' => round($o['value'], 4),
                    'masuk_qty' => round($dq['masuk'], 6), 'masuk_value' => round($dv['masuk'], 4),
                    'keluar_qty' => round($dq['keluar'], 6), 'hpp_keluar_value' => round($dv['keluar'], 4),
                    'adjustment_qty' => round($dq['lain'], 6), 'adjustment_value' => round($dv['lain'], 4),
                    'closing_qty' => round($closeQ, 6), 'closing_value' => round($closeV, 4), 'tx_count' => (int) $raw['tx_count'],
                ];
                $balance[$iid] = ['qty' => $closeQ, 'value' => $closeV];
            }
        }
        return $out;
    }
}
