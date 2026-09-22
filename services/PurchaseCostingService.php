<?php
declare(strict_types=1);

namespace App\Services;

/**
 * PHASE V2.7 — Purchase Costing. Pure calculation engine: every method here
 * is a stateless function over plain arrays/scalars, never touching the
 * database, FIFO, or inventory_batches. FifoService::postIn() is NEVER
 * rewritten — the caller (public/index.php's POST /transactions/in route)
 * calls buildCostPreview() first, derives an equivalent unit_price_input
 * from each line's final_inventory_cost, and only THEN calls the existing,
 * unmodified FifoService::postIn() with that value. See that route's own
 * comment for exactly how the two connect.
 *
 * ARCHITECTURE NOTE (read before extending this file): today's Stock IN
 * flow posts exactly ONE item per API call (FifoService::postIn() creates
 * exactly one inventory_transactions row with exactly one line — this is
 * true for every existing caller: the manual Transaksi Masuk wizard,
 * TransferService, ProductionService, ImportOpeningStockService). V2.7
 * does NOT introduce a multi-item purchase invoice UI/table — that would
 * be a materially larger architecture change (a new header table grouping
 * several inventory_transactions rows, a redesigned multi-row entry
 * screen) outside this phase's scope. Every method below is still written
 * to handle an arbitrary number of lines ($lines is always an array),
 * because the SAME proportional-allocation math is what a future
 * multi-line invoice would need unchanged — but in the real Stock IN path
 * today, $lines always has exactly one element. buildCostPreview()'s
 * reconciliation guarantees hold for N=1 exactly as they would for N>1.
 *
 * Money is rounded to MONEY_SCALE (4 decimals, matching
 * FifoService::MONEY_SCALE) at every step — never accumulated unrounded
 * and rounded once at the end — so every intermediate figure shown in the
 * Cost Preview is the exact figure persisted.
 */
final class PurchaseCostingService
{
    private const MONEY_SCALE = 4;
    private const EPSILON = 0.0001;

    /**
     * @param array{
     *   invoice_discount_type: string, invoice_discount_value: float,
     *   ppn_treatment: string, ppn_rate: float, ppn_creditable_pct: float,
     *   freight_treatment: string, freight_amount: float
     * } $header
     * @param list<array{qty: float, gross_unit_price: float, base_qty: float,
     *   line_discount_type: string, line_discount_value: float}> $lines
     * @return array{header: array, lines: list<array>}
     */
    public static function buildCostPreview(array $header, array $lines): array
    {
        if ($lines === []) {
            throw new ValidationException(['at least one purchase line is required']);
        }
        self::assertEnum('invoice_discount_type', $header['invoice_discount_type'] ?? 'NONE', ['PERCENT', 'AMOUNT', 'NONE']);
        self::assertEnum('ppn_treatment', $header['ppn_treatment'] ?? 'NONE', ['CREDITABLE', 'NON_CREDITABLE', 'PARTIALLY_CREDITABLE', 'NONE']);
        self::assertEnum('freight_treatment', $header['freight_treatment'] ?? 'NONE', ['CAPITALIZE', 'EXPENSE', 'NONE']);

        $ppnRate = (float) ($header['ppn_rate'] ?? 0);
        $ppnTreatment = $header['ppn_treatment'] ?? 'NONE';
        $ppnCreditablePct = (float) ($header['ppn_creditable_pct'] ?? 0);
        $freightTreatment = $header['freight_treatment'] ?? 'NONE';
        $freightAmount = self::round((float) ($header['freight_amount'] ?? 0));
        if ($freightAmount < 0) {
            throw new ValidationException(['freight_amount must not be negative']);
        }
        if ($ppnRate < 0) {
            throw new ValidationException(['ppn_rate must not be negative']);
        }

        // ---- Step 1: per-line gross + line discount ----
        $lineRows = [];
        foreach ($lines as $i => $l) {
            $qty = (float) $l['qty'];
            $grossUnitPrice = (float) $l['gross_unit_price'];
            $baseQty = (float) $l['base_qty'];
            if (!($qty > 0) || $grossUnitPrice < 0 || !($baseQty > 0)) {
                throw new ValidationException(["line " . ($i + 1) . ": qty and base_qty must be > 0, gross_unit_price must be >= 0"]);
            }
            $grossAmount = self::round($qty * $grossUnitPrice);
            $lineDiscount = self::calculateDiscount($grossAmount, $l['line_discount_type'] ?? 'NONE', (float) ($l['line_discount_value'] ?? 0), "line " . ($i + 1) . " discount");
            $netAfterLineDiscount = self::round($grossAmount - $lineDiscount['amount']);
            $lineRows[$i] = [
                'gross_unit_price_input' => $grossUnitPrice,
                'gross_amount' => $grossAmount,
                'base_qty' => $baseQty,
                'line_discount_type' => $l['line_discount_type'] ?? 'NONE',
                'line_discount_value' => (float) ($l['line_discount_value'] ?? 0),
                'line_discount_amount' => $lineDiscount['amount'],
                'net_after_line_discount' => $netAfterLineDiscount,
            ];
        }

        $grossPurchase = self::round(array_sum(array_column($lineRows, 'gross_amount')));
        $lineDiscountTotal = self::round(array_sum(array_column($lineRows, 'line_discount_amount')));
        $netAfterLineDiscountTotal = self::round(array_sum(array_column($lineRows, 'net_after_line_discount')));

        // ---- Step 2: invoice discount, allocated proportionally to each line's net-after-line-discount ----
        $invoiceDiscount = self::calculateDiscount($netAfterLineDiscountTotal, $header['invoice_discount_type'] ?? 'NONE', (float) ($header['invoice_discount_value'] ?? 0), 'invoice discount');
        $invoiceDiscountAllocated = self::allocateProportionally(array_column($lineRows, 'net_after_line_discount'), $invoiceDiscount['amount']);

        foreach ($lineRows as $i => &$row) {
            $row['invoice_discount_allocated'] = $invoiceDiscountAllocated[$i];
            $row['net_purchase_before_tax'] = self::round($row['net_after_line_discount'] - $invoiceDiscountAllocated[$i]);
        }
        unset($row);

        $netPurchaseBeforeTax = self::round(array_sum(array_column($lineRows, 'net_purchase_before_tax')));

        // ---- Step 3: PPN — computed on net purchase before tax, allocated to lines, then split creditable/non-creditable PER LINE (so the split always reconciles exactly, never just in aggregate) ----
        $ppnAmount = self::round($netPurchaseBeforeTax * $ppnRate / 100);
        $ppnAllocated = self::allocateProportionally(array_column($lineRows, 'net_purchase_before_tax'), $ppnAmount);

        foreach ($lineRows as $i => &$row) {
            $row['ppn_allocated'] = $ppnAllocated[$i];
            $split = self::splitPpn($ppnAllocated[$i], $ppnTreatment, $ppnCreditablePct);
            $row['ppn_creditable_allocated'] = $split['creditable'];
            $row['ppn_non_creditable_allocated'] = $split['non_creditable'];
        }
        unset($row);

        $ppnCreditableAmount = self::round(array_sum(array_column($lineRows, 'ppn_creditable_allocated')));
        $ppnNonCreditableAmount = self::round(array_sum(array_column($lineRows, 'ppn_non_creditable_allocated')));

        // ---- Step 4: freight — allocated to lines only if CAPITALIZE; still recorded on the header (and in invoice_total) either way ----
        $freightAllocated = $freightTreatment === 'CAPITALIZE'
            ? self::allocateProportionally(array_column($lineRows, 'net_purchase_before_tax'), $freightAmount)
            : array_fill(0, count($lineRows), 0.0);

        foreach ($lineRows as $i => &$row) {
            $row['freight_allocated'] = $freightAllocated[$i];
            $row['final_inventory_cost'] = self::round($row['net_purchase_before_tax'] + $row['ppn_non_creditable_allocated'] + $row['freight_allocated']);
            $row['final_unit_cost_base'] = self::round($row['final_inventory_cost'] / $row['base_qty']);
        }
        unset($row);

        $inventoryCostTotal = self::round(array_sum(array_column($lineRows, 'final_inventory_cost')));
        $invoiceTotal = self::round($netPurchaseBeforeTax + $ppnAmount + $freightAmount);

        // ---- Hard reconciliation — never silently corrected (V2.7.6) ----
        self::assertReconciles('invoice_discount_allocated', array_sum($invoiceDiscountAllocated), $invoiceDiscount['amount']);
        self::assertReconciles('ppn_allocated', array_sum($ppnAllocated), $ppnAmount);
        self::assertReconciles('freight_allocated', array_sum($freightAllocated), $freightTreatment === 'CAPITALIZE' ? $freightAmount : 0.0);
        self::assertReconciles('inventory_cost_total', $inventoryCostTotal, array_sum(array_column($lineRows, 'final_inventory_cost')));

        return [
            'header' => [
                'gross_purchase' => $grossPurchase,
                'line_discount_total' => $lineDiscountTotal,
                'invoice_discount_type' => $header['invoice_discount_type'] ?? 'NONE',
                'invoice_discount_value' => (float) ($header['invoice_discount_value'] ?? 0),
                'invoice_discount_amount' => $invoiceDiscount['amount'],
                'net_purchase_before_tax' => $netPurchaseBeforeTax,
                'ppn_treatment' => $ppnTreatment,
                'ppn_rate' => $ppnRate,
                'ppn_creditable_pct' => $ppnCreditablePct,
                'ppn_amount' => $ppnAmount,
                'ppn_creditable_amount' => $ppnCreditableAmount,
                'ppn_non_creditable_amount' => $ppnNonCreditableAmount,
                'freight_treatment' => $freightTreatment,
                'freight_amount' => $freightAmount,
                'invoice_total' => $invoiceTotal,
                'inventory_cost_total' => $inventoryCostTotal,
            ],
            'lines' => array_values($lineRows),
        ];
    }

    /**
     * PHASE V2.7 — generic N-way proportional allocator, deterministic
     * rounding: each share is rounded independently, then whatever penny-
     * level remainder is left over (total - sum of rounded shares) is
     * added to the LAST line with a positive base amount. Never drops a
     * rounding difference silently — Σallocated === $total exactly, to
     * MONEY_SCALE, by construction. A base amount of 0 always gets an
     * allocation of exactly 0 (never a phantom share).
     *
     * @param list<float> $baseAmounts
     * @return list<float>
     */
    public static function allocateProportionally(array $baseAmounts, float $total): array
    {
        $n = count($baseAmounts);
        if ($n === 0) {
            return [];
        }
        $total = self::round($total);
        $sumBase = array_sum($baseAmounts);
        if ($sumBase <= 0 || abs($total) < self::EPSILON) {
            return array_fill(0, $n, 0.0);
        }

        $allocated = [];
        $runningSum = 0.0;
        $lastEligibleIdx = null;
        foreach ($baseAmounts as $i => $base) {
            if ($base > 0) {
                $lastEligibleIdx = $i;
            }
        }
        foreach ($baseAmounts as $i => $base) {
            if ($base <= 0) {
                $allocated[$i] = 0.0;
                continue;
            }
            $share = self::round($total * ($base / $sumBase));
            $allocated[$i] = $share;
            $runningSum = self::round($runningSum + $share);
        }
        $remainder = self::round($total - $runningSum);
        if (abs($remainder) >= self::EPSILON && $lastEligibleIdx !== null) {
            $allocated[$lastEligibleIdx] = self::round($allocated[$lastEligibleIdx] + $remainder);
        }
        return $allocated;
    }

    /** @return array{amount: float} */
    public static function calculateDiscount(float $baseAmount, string $type, float $value, string $label = 'discount'): array
    {
        self::assertEnum($label, $type, ['PERCENT', 'AMOUNT', 'NONE']);
        if ($value < 0) {
            throw new ValidationException(["{$label} value must not be negative"]);
        }
        $amount = match ($type) {
            'PERCENT' => self::round($baseAmount * $value / 100),
            'AMOUNT' => self::round($value),
            default => 0.0,
        };
        if ($amount > $baseAmount + self::EPSILON) {
            throw new ValidationException(["{$label} ({$amount}) must not exceed the base amount it applies to ({$baseAmount})"]);
        }
        return ['amount' => $amount];
    }

    /**
     * CREDITABLE: 0 non-creditable. NON_CREDITABLE: 0 creditable.
     * PARTIALLY_CREDITABLE: split by $creditablePct (0-100). NONE (no PPN
     * on this purchase at all): both 0.
     *
     * @return array{creditable: float, non_creditable: float}
     */
    public static function splitPpn(float $ppnAmount, string $treatment, float $creditablePct): array
    {
        self::assertEnum('ppn_treatment', $treatment, ['CREDITABLE', 'NON_CREDITABLE', 'PARTIALLY_CREDITABLE', 'NONE']);
        return match ($treatment) {
            'CREDITABLE' => ['creditable' => $ppnAmount, 'non_creditable' => 0.0],
            'NON_CREDITABLE' => ['creditable' => 0.0, 'non_creditable' => $ppnAmount],
            'PARTIALLY_CREDITABLE' => (function () use ($ppnAmount, $creditablePct) {
                if ($creditablePct < 0 || $creditablePct > 100) {
                    throw new ValidationException(['ppn_creditable_pct must be between 0 and 100']);
                }
                $creditable = self::round($ppnAmount * $creditablePct / 100);
                return ['creditable' => $creditable, 'non_creditable' => self::round($ppnAmount - $creditable)];
            })(),
            default => ['creditable' => 0.0, 'non_creditable' => 0.0],
        };
    }

    private static function assertEnum(string $label, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new ValidationException(["{$label} must be one of: " . implode(', ', $allowed)]);
        }
    }

    private static function assertReconciles(string $label, float $a, float $b): void
    {
        if (abs($a - $b) >= self::EPSILON) {
            throw new ValidationException(["Cost reconciliation failed for {$label}: {$a} != {$b} — POST blocked, nothing was posted"]);
        }
    }

    private static function round(float $v): float
    {
        return round($v, self::MONEY_SCALE);
    }
}
