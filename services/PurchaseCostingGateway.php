<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.8 — extracted verbatim (byte-for-byte identical logic) from
 * public/index.php's own inv_purchase_costing_preview()/
 * inv_persist_purchase_costing() free functions, which POST /transactions/in
 * has used since V2.7. Those two functions were only ever reachable from
 * within public/index.php's own request lifecycle — fine for the HTTP
 * route itself, but it meant no OTHER caller (like V2.8's
 * ImportLiveTransactionService, which posts real IN rows outside of any
 * HTTP request) could reuse the same single-line "derive an equivalent
 * unit_price_input, call FifoService::postIn(), then persist the
 * breakdown" glue without either duplicating it or depending on
 * public/index.php having executed first.
 *
 * This class is that glue, callable from anywhere. PurchaseCostingService
 * itself (the actual PPN/discount/freight math) is completely untouched —
 * still a pure, DB-free calculation engine. public/index.php's route now
 * delegates its two free functions here (see the comment there); nothing
 * about its behavior changed, only where the code physically lives.
 */
final class PurchaseCostingGateway
{
    /** @return array{preview: array, equivalent_unit_price_input: float} */
    public static function preview(PDO $pdo, array $input): array
    {
        $itemId = (int) ($input['item_id'] ?? 0);
        $unitId = (int) ($input['input_unit_id'] ?? 0);
        $qty = (float) ($input['input_qty'] ?? 0);
        $grossPrice = (float) ($input['unit_price_input'] ?? 0);
        $txDate = (string) ($input['transaction_date'] ?? '');

        $conversion = UnitConversionService::getActiveConversion($pdo, $itemId, $unitId, $txDate);
        if ($conversion === null) {
            throw new UnitConversionNotApprovedException($itemId, $unitId);
        }
        $factor = (float) $conversion['conversion_to_base'];
        $baseQty = round($qty * $factor, 6);

        $preview = PurchaseCostingService::buildCostPreview(
            [
                'invoice_discount_type' => $input['invoice_discount_type'] ?? 'NONE',
                'invoice_discount_value' => (float) ($input['invoice_discount_value'] ?? 0),
                'ppn_treatment' => $input['ppn_treatment'] ?? 'NONE',
                'ppn_rate' => (float) ($input['ppn_rate'] ?? 0),
                'ppn_creditable_pct' => (float) ($input['ppn_creditable_pct'] ?? 0),
                'freight_treatment' => $input['freight_treatment'] ?? 'NONE',
                'freight_amount' => (float) ($input['freight_amount'] ?? 0),
            ],
            [[
                'qty' => $qty, 'gross_unit_price' => $grossPrice, 'base_qty' => $baseQty,
                'line_discount_type' => $input['line_discount_type'] ?? 'NONE',
                'line_discount_value' => (float) ($input['line_discount_value'] ?? 0),
            ]]
        );

        $line = $preview['lines'][0];
        $equivalentUnitPriceInput = $qty > 0 ? round($line['final_inventory_cost'] / $qty, 4) : 0.0;

        return ['preview' => $preview, 'equivalent_unit_price_input' => $equivalentUnitPriceInput];
    }

    /**
     * Persists purchase_invoice_headers + purchase_line_costs for a
     * just-posted transaction — and cross-checks the precomputed preview
     * against what FifoService ACTUALLY posted (never silently trusts the
     * preview). A mismatch throws and, since this always runs inside the
     * same Database::transaction() as the FifoService::postIn() call,
     * rolls back the whole thing including the FIFO batch just created —
     * nothing is ever left half-posted.
     */
    public static function persist(PDO $pdo, array $posted, int $createdBy, array $costing): void
    {
        $header = $costing['preview']['header'];
        $line = $costing['preview']['lines'][0];

        $actualInventoryCost = round((float) $posted['unit_cost_base'] * (float) $posted['base_qty'], 4);
        if (abs($actualInventoryCost - $line['final_inventory_cost']) >= 0.0001) {
            throw new ValidationException(["Cost reconciliation failed: FIFO posted {$actualInventoryCost} but purchase costing computed {$line['final_inventory_cost']} — nothing was committed"]);
        }

        $pdo->prepare(
            'INSERT INTO purchase_invoice_headers
                (transaction_id, gross_purchase, line_discount_total, invoice_discount_type, invoice_discount_value,
                 invoice_discount_amount, net_purchase_before_tax, ppn_treatment, ppn_rate, ppn_creditable_pct, ppn_amount,
                 ppn_creditable_amount, ppn_non_creditable_amount, freight_treatment, freight_amount, invoice_total,
                 inventory_cost_total, created_by)
             VALUES (:tx, :gross, :ldt, :idt, :idv, :ida, :npbt, :ppnt, :ppnr, :ppncp, :ppna, :ppnca, :ppnnca, :ft, :fa, :it, :ict, :cb)'
        )->execute([
            'tx' => $posted['transaction_id'], 'gross' => $header['gross_purchase'], 'ldt' => $header['line_discount_total'],
            'idt' => $header['invoice_discount_type'], 'idv' => $header['invoice_discount_value'], 'ida' => $header['invoice_discount_amount'],
            'npbt' => $header['net_purchase_before_tax'], 'ppnt' => $header['ppn_treatment'], 'ppnr' => $header['ppn_rate'],
            'ppncp' => $header['ppn_creditable_pct'], 'ppna' => $header['ppn_amount'], 'ppnca' => $header['ppn_creditable_amount'],
            'ppnnca' => $header['ppn_non_creditable_amount'], 'ft' => $header['freight_treatment'], 'fa' => $header['freight_amount'],
            'it' => $header['invoice_total'], 'ict' => $header['inventory_cost_total'], 'cb' => $createdBy,
        ]);

        $pdo->prepare(
            'INSERT INTO purchase_line_costs
                (transaction_line_id, transaction_id, gross_unit_price_input, gross_amount, line_discount_type, line_discount_value,
                 line_discount_amount, net_after_line_discount, invoice_discount_allocated, net_purchase_before_tax, ppn_allocated,
                 ppn_creditable_allocated, ppn_non_creditable_allocated, freight_allocated, final_inventory_cost, final_unit_cost_base)
             VALUES (:line_id, :tx, :gup, :ga, :ldt, :ldv, :lda, :nald, :ida, :npbt, :ppna, :ppnca, :ppnnca, :fa, :fic, :fucb)'
        )->execute([
            'line_id' => $posted['line_id'], 'tx' => $posted['transaction_id'],
            'gup' => $line['gross_unit_price_input'], 'ga' => $line['gross_amount'],
            'ldt' => $line['line_discount_type'], 'ldv' => $line['line_discount_value'], 'lda' => $line['line_discount_amount'],
            'nald' => $line['net_after_line_discount'], 'ida' => $line['invoice_discount_allocated'], 'npbt' => $line['net_purchase_before_tax'],
            'ppna' => $line['ppn_allocated'], 'ppnca' => $line['ppn_creditable_allocated'], 'ppnnca' => $line['ppn_non_creditable_allocated'],
            'fa' => $line['freight_allocated'], 'fic' => $line['final_inventory_cost'], 'fucb' => $line['final_unit_cost_base'],
        ]);
    }
}
