<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * STOCK IN V2 — multi-line purchase entry (the table-first "Daftar Barang"
 * sheet): per-item PPN, per-item discount (% or Rp), ONE invoice-level
 * discount (% or Rp) and an optional shipping cost, posted atomically.
 *
 * NOTHING in the proven engines is changed. This class only
 *   1. does the commercial arithmetic the sheet displays (quote()), and
 *   2. translates every item row into the SAME single-line input that the
 *      existing POST /transactions/in already accepts, then calls the
 *      unmodified PurchaseCostingGateway::preview()/persist() and
 *      FifoService::postIn() — one inventory_transactions row per item, as
 *      every other Stock IN caller already does (void / reports / FIFO all
 *      keep working per item). All rows of one entry share the entry's
 *      reference_no and are posted inside ONE database transaction (all or
 *      nothing) with per-line idempotency keys derived from the request uuid.
 *
 * INVOICE-LEVEL PPN (the sheet's current mode — request has a top-level `ppn_rate`): PPN is NOT chosen per item. Rows carry no PPN; the rate is applied ONCE
 * at the end, on the DPP that is left after the invoice discount:
 *   base_i = qty_i x price_i ; itemDisc_i (% or Rp, <= base_i) ; dpp_i = base_i - itemDisc_i ; row Total = dpp_i
 *   Subtotal = SUM(dpp_i) ; InvDisc = Subtotal x pct | nominal (<= Subtotal)  [on the DPP, before PPN]
 *   DPP after discount = Subtotal - InvDisc ; PPN = DPP after discount x rate ; Grand Total = DPP after discount + PPN + Shipping
 * The invoice discount is split over the rows in proportion to dpp_i (exact), shipping in proportion to the net DPP, and each row's share of the PPN is
 * (net DPP_i x rate) — the SAME per-line figures the unchanged V2.7 costing engine persists (purchase_line_costs), so reports / FIFO cost keep working.
 * A request WITHOUT a top-level ppn_rate keeps the legacy per-item PPN formula below (older clients, imports, tests).
 *
 * LEGACY COMMERCIAL FORMULA (per-item PPN; used only when the request has no top-level ppn_rate)
 *   base_i      = qty_i x price_i
 *   itemDisc_i  = base_i x pct   |  nominal            (<= base_i)
 *   dpp_i       = base_i - itemDisc_i
 *   ppn_i       = dpp_i x rate_i
 *   total_i     = dpp_i + ppn_i                        (the row "Total")
 *   Subtotal    = SUM(total_i)
 *   InvDisc     = Subtotal x pct | nominal             (<= Subtotal)
 *   Grand Total = Subtotal - InvDisc + Shipping
 *
 * INVOICE DISCOUNT ALLOCATION (backend): the invoice discount is defined on
 * the PPN-inclusive Subtotal, so it is split across rows in proportion to
 * total_i (PurchaseCostingService::allocateProportionally — remainder to the
 * last eligible row, so the shares sum EXACTLY). Row i's share A_i is a
 * tax-inclusive amount, so it reduces that row's DPP by A_i / (1 + rate_i)
 * and therefore its PPN by the matching part: the row's payable becomes
 * total_i - A_i and SUM(payable) = Subtotal - InvDisc (to rounding, which is
 * folded into the *effective* invoice discount that is displayed and stored,
 * so Subtotal - InvDisc_eff + Shipping = Grand Total holds exactly).
 *
 * SHIPPING ALLOCATION: allocated across rows in proportion to each row's
 * net DPP (after item + invoice discount), the exact rule
 * PurchaseCostingService already uses for freight.
 *
 * INVENTORY (FIFO) COST — the existing, unchanged V2.7 rule:
 *   inventory cost_i = net DPP_i + non-creditable PPN_i + capitalised freight_i
 * PPN defaults to CREDITABLE (recoverable input tax -> NOT capitalised) and
 * shipping defaults to NOT capitalised, i.e. exactly the cost behaviour a
 * Stock IN with no V2.7 options has always had; capitalising is an explicit,
 * visible choice. Master/default price is never written: there is no master
 * price field — the default "Harga Beli" is the reference price resolved by
 * ItemPriceService from item_price_history, and the edited transaction price
 * only ever lands in item_price_history through FifoService::postIn()'s own
 * PriceAnomalyService::recordPrice() (as the equivalent cost-based price, the
 * existing behaviour).
 */
final class PurchaseInvoiceService
{
    private const MONEY_SCALE = 4;
    private const MAX_LINES = 200;

    /** Pure + read-only: validates and prices the whole entry. Never writes. */
    public static function quote(PDO $pdo, array $in): array
    {
        $errors = [];
        $date = (string) ($in['transaction_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            $errors[] = 'transaction_date is required (YYYY-MM-DD)';
        }
        $warehouseId = (int) ($in['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            $errors[] = 'warehouse_id is required';
        }
        $supplierId = isset($in['supplier_id']) && $in['supplier_id'] !== '' && $in['supplier_id'] !== null ? (int) $in['supplier_id'] : null;
        if ($supplierId !== null) {
            $s = $pdo->prepare('SELECT is_active FROM suppliers WHERE id = :id');
            $s->execute(['id' => $supplierId]);
            $active = $s->fetchColumn();
            if ($active === false) {
                $errors[] = "supplier {$supplierId} does not exist";
            } elseif ((int) $active !== 1) {
                $errors[] = "supplier {$supplierId} is not active";
            }
        }

        $rawLines = $in['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            $errors[] = 'at least one item line is required';
            $rawLines = [];
        }
        if (count($rawLines) > self::MAX_LINES) {
            $errors[] = 'at most ' . self::MAX_LINES . ' item lines are allowed per entry';
            $rawLines = array_slice($rawLines, 0, self::MAX_LINES);
        }

        $ppnTreatment = (string) ($in['ppn_treatment'] ?? 'CREDITABLE');
        if (!in_array($ppnTreatment, ['CREDITABLE', 'NON_CREDITABLE', 'PARTIALLY_CREDITABLE'], true)) {
            $errors[] = 'ppn_treatment must be CREDITABLE, NON_CREDITABLE or PARTIALLY_CREDITABLE';
            $ppnTreatment = 'CREDITABLE';
        }
        $ppnCreditablePct = (float) ($in['ppn_creditable_pct'] ?? 0);
        if ($ppnTreatment === 'PARTIALLY_CREDITABLE' && ($ppnCreditablePct < 0 || $ppnCreditablePct > 100)) {
            $errors[] = 'ppn_creditable_pct must be between 0 and 100';
        }

        // invoice-level PPN rate: present → PPN is computed once at the end (see the class docblock); absent → legacy per-item PPN
        $invoiceRate = null;
        if (array_key_exists('ppn_rate', $in) && $in['ppn_rate'] !== null && $in['ppn_rate'] !== '') {
            $invoiceRate = (float) $in['ppn_rate'];
            if ($invoiceRate < 0 || $invoiceRate > 100) {
                $errors[] = 'ppn_rate harus antara 0 dan 100';
                $invoiceRate = 0.0;
            }
        }

        $freightAmount = self::round((float) ($in['freight_amount'] ?? 0));
        if ($freightAmount < 0) {
            $errors[] = 'freight_amount must not be negative';
            $freightAmount = 0.0;
        }
        $freightCapitalize = !empty($in['freight_capitalize']);

        $invType = (string) ($in['invoice_discount_type'] ?? 'NONE');
        $invValue = (float) ($in['invoice_discount_value'] ?? 0);
        if (!in_array($invType, ['NONE', 'PERCENT', 'AMOUNT'], true)) {
            $errors[] = 'invoice_discount_type must be NONE, PERCENT or AMOUNT';
            $invType = 'NONE';
        }
        if ($invValue < 0 || ($invType === 'PERCENT' && $invValue > 100)) {
            $errors[] = 'invoice_discount_value is out of range';
            $invValue = 0.0;
        }

        // ---- step 1: per-row commercial figures ----
        $rows = [];
        foreach (array_values($rawLines) as $i => $l) {
            $n = $i + 1;
            $row = ['line_no' => $n, 'errors' => []];
            $itemId = (int) ($l['item_id'] ?? 0);
            $unitId = (int) ($l['input_unit_id'] ?? 0);
            $qty = (float) ($l['input_qty'] ?? 0);
            $price = (float) ($l['unit_price_input'] ?? -1);
            $rate = $invoiceRate !== null ? 0.0 : (float) ($l['ppn_rate'] ?? 0);   // invoice-level mode: no per-item PPN
            $dType = (string) ($l['discount_type'] ?? 'NONE');
            $dVal = (float) ($l['discount_value'] ?? 0);

            $item = null;
            if ($itemId <= 0) {
                $row['errors'][] = 'barang belum dipilih';
            } else {
                $st = $pdo->prepare('SELECT id, sku, name, status FROM items WHERE id = :id');
                $st->execute(['id' => $itemId]);
                $item = $st->fetch() ?: null;
                if ($item === null) {
                    $row['errors'][] = 'barang tidak ditemukan di Master Barang';
                } elseif ($item['status'] !== 'ACTIVE') {
                    $row['errors'][] = 'barang tidak aktif';
                }
            }
            $factor = null;
            if ($item !== null) {
                if ($unitId <= 0) {
                    $row['errors'][] = 'satuan belum dipilih';
                } else {
                    $factor = UnitConversionService::resolveConversionFactor($pdo, $itemId, $unitId, $date !== '' ? $date : date('Y-m-d'));
                    if ($factor === null) {
                        $row['errors'][] = 'satuan tidak valid / belum disetujui untuk barang ini';
                    }
                }
            }
            if (!($qty > 0)) {
                $row['errors'][] = 'qty harus lebih dari 0';
            }
            if ($price < 0) {
                $row['errors'][] = 'harga beli tidak boleh negatif';
            }
            if ($rate < 0 || $rate > 100) {
                $row['errors'][] = 'PPN harus antara 0 dan 100';
            }
            if (!in_array($dType, ['NONE', 'PERCENT', 'AMOUNT'], true)) {
                $row['errors'][] = 'mode diskon tidak valid';
                $dType = 'NONE';
            }
            if ($dVal < 0 || ($dType === 'PERCENT' && $dVal > 100)) {
                $row['errors'][] = 'nilai diskon tidak valid';
                $dVal = 0.0;
            }

            $base = self::round(max(0.0, $qty) * max(0.0, $price));
            $itemDisc = 0.0;
            if ($dType === 'PERCENT') {
                $itemDisc = self::round($base * $dVal / 100);
            } elseif ($dType === 'AMOUNT') {
                $itemDisc = self::round($dVal);
            }
            if ($itemDisc > $base + 0.0001) {
                $row['errors'][] = 'diskon item melebihi nilai barang';
                $itemDisc = $base;
            }
            $dpp = self::round($base - $itemDisc);
            $ppn = self::round($dpp * $rate / 100);

            $row += [
                'item_id' => $itemId, 'item_name' => $item['name'] ?? null,
                'input_unit_id' => $unitId, 'input_qty' => $qty, 'unit_price_input' => $price,
                'conversion_factor' => $factor, 'base_qty' => $factor !== null ? round($qty * $factor, 6) : null,
                'ppn_rate' => $invoiceRate ?? $rate, 'discount_type' => $dType, 'discount_value' => $dVal,
                'base_amount' => $base, 'item_discount' => $itemDisc, 'dpp' => $dpp, 'ppn' => $ppn,
                'total' => self::round($dpp + $ppn),
            ];
            $rows[] = $row;
        }

        $subtotal = self::round(array_sum(array_column($rows, 'total')));

        // ---- step 2: invoice discount (legacy: on the PPN-inclusive subtotal; invoice-level PPN: on the DPP subtotal, before PPN) ----
        $invDisc = 0.0;
        if ($invType === 'PERCENT') {
            $invDisc = self::round($subtotal * $invValue / 100);
        } elseif ($invType === 'AMOUNT') {
            $invDisc = self::round($invValue);
        }
        if ($invDisc > $subtotal + 0.0001) {
            $errors[] = 'diskon invoice melebihi subtotal';
            $invDisc = $subtotal;
        }
        $alloc = PurchaseCostingService::allocateProportionally(array_column($rows, 'total'), $invDisc);

        // ---- step 3: re-express each share on the DPP, then let the existing costing engine price every row ----
        $netDpp = [];
        foreach ($rows as $i => &$r) {
            $r['invoice_discount_share'] = $alloc[$i] ?? 0.0;
            $d = $invoiceRate !== null ? $r['invoice_discount_share'] : round($r['invoice_discount_share'] / (1 + $r['ppn_rate'] / 100), self::MONEY_SCALE);
            $d = min($d, $r['dpp']);
            $r['invoice_discount_dpp'] = $d;
            $netDpp[$i] = self::round($r['dpp'] - $d);
        }
        unset($r);
        $freightShares = PurchaseCostingService::allocateProportionally($netDpp, $freightAmount);
        $freightTreatment = $freightAmount > 0 ? ($freightCapitalize ? 'CAPITALIZE' : 'EXPENSE') : 'NONE';

        $costingByLine = [];
        $payableSum = 0.0;
        $inventoryCostTotal = 0.0;
        $ppnSum = 0.0;
        foreach ($rows as $i => &$r) {
            if ($r['errors'] !== []) {
                continue;
            }
            $lineInput = [
                'item_id' => $r['item_id'], 'input_unit_id' => $r['input_unit_id'], 'input_qty' => $r['input_qty'],
                'unit_price_input' => $r['unit_price_input'], 'transaction_date' => $date,
                'line_discount_type' => $r['discount_type'], 'line_discount_value' => $r['discount_value'],
                'invoice_discount_type' => $r['invoice_discount_dpp'] > 0 ? 'AMOUNT' : 'NONE',
                'invoice_discount_value' => $r['invoice_discount_dpp'],
                'ppn_treatment' => $r['ppn_rate'] > 0 ? $ppnTreatment : 'NONE',
                'ppn_rate' => $r['ppn_rate'], 'ppn_creditable_pct' => $ppnCreditablePct,
                'freight_treatment' => $freightShares[$i] > 0 ? $freightTreatment : 'NONE',
                'freight_amount' => $freightShares[$i],
            ];
            try {
                $costing = PurchaseCostingGateway::preview($pdo, $lineInput);
            } catch (ValidationException $e) {
                $r['errors'][] = implode('; ', $e->errors);
                continue;
            }
            // FifoService stores the layer as unit_cost_base (4 dp) x base_qty, so the
            // amount that really enters inventory is that product — not the engine's
            // unrounded final_inventory_cost. Record exactly what FIFO will hold (the
            // gateway insists the two agree to 0.0001). The difference is pure
            // 4-dp storage rounding; anything larger than Rp 0,05 is refused.
            $factor = (float) $r['conversion_factor'];
            $baseQtyExact = round($r['input_qty'] * $factor, 6);
            $unitCostBase = round($costing['equivalent_unit_price_input'] / $factor, self::MONEY_SCALE);
            $actualCost = round($unitCostBase * $baseQtyExact, self::MONEY_SCALE);
            if (abs($actualCost - $costing['preview']['lines'][0]['final_inventory_cost']) > 0.05) {
                $r['errors'][] = 'biaya persediaan tidak dapat direpresentasikan dengan presisi FIFO — periksa harga/diskon/satuan';
                continue;
            }
            $costing['preview']['lines'][0]['final_inventory_cost'] = $actualCost;
            $costing['preview']['lines'][0]['final_unit_cost_base'] = $unitCostBase;
            $costing['preview']['header']['inventory_cost_total'] = $actualCost;
            $h = $costing['preview']['header'];
            $ln = $costing['preview']['lines'][0];
            $costingByLine[$i] = $costing;
            $r['net_dpp'] = $h['net_purchase_before_tax'];
            $r['ppn_final'] = $h['ppn_amount'];
            $r['payable'] = self::round($h['net_purchase_before_tax'] + $h['ppn_amount']);
            $r['freight_share'] = $freightShares[$i];
            $r['ppn_non_creditable'] = $h['ppn_non_creditable_amount'];
            $r['inventory_cost'] = $ln['final_inventory_cost'];
            $r['unit_cost_base'] = $ln['final_unit_cost_base'];
            $r['equivalent_unit_price_input'] = $costing['equivalent_unit_price_input'];
            $payableSum += $r['payable'];
            $inventoryCostTotal += $ln['final_inventory_cost'];
            $ppnSum += $h['ppn_amount'];
        }
        unset($r);

        foreach ($rows as $r) {
            foreach ($r['errors'] as $e) {
                $errors[] = "baris {$r['line_no']}: {$e}";
            }
        }

        $payableSum = self::round($payableSum);
        if ($invoiceRate !== null) {
            // invoice-level PPN: Subtotal (DPP) - InvDisc = DPP after discount ; PPN on it ; Grand = DPP after discount + PPN + Shipping
            $dppAfter = self::round(array_sum(array_map(static fn (array $r) => (float) ($r['net_dpp'] ?? $r['dpp']), $rows)));
            $invDiscEffective = $errors === [] ? self::round($subtotal - $dppAfter) : $invDisc;
            $ppnInvoice = self::round($ppnSum);
            $grandTotal = self::round($dppAfter + $ppnInvoice + $freightAmount);
        } else {
            $dppAfter = null;
            // effective invoice discount: folds sub-rupiah rounding so that
            // Subtotal - InvDisc + Shipping == Grand Total holds exactly.
            $invDiscEffective = $errors === [] ? self::round($subtotal - $payableSum) : $invDisc;
            $grandTotal = self::round($subtotal - $invDiscEffective + $freightAmount);
        }

        $dppTotal = self::round(array_sum(array_column($rows, 'dpp')));
        $itemDiscTotal = self::round(array_sum(array_column($rows, 'item_discount')));

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'lines' => array_map(static function (array $r): array {
                unset($r['conversion_factor']);
                return $r;
            }, $rows),
            'totals' => [
                'total_items' => count($rows),
                'gross_amount' => self::round(array_sum(array_column($rows, 'base_amount'))),
                'item_discount_total' => $itemDiscTotal,
                'dpp_total' => $dppTotal,
                'ppn_total_before_invoice_discount' => self::round(array_sum(array_column($rows, 'ppn'))),
                'subtotal' => $subtotal,
                'invoice_discount_type' => $invType,
                'invoice_discount_value' => $invValue,
                'invoice_discount' => $invDiscEffective,
                'freight_amount' => $freightAmount,
                'freight_treatment' => $freightTreatment,
                'ppn_treatment' => $ppnTreatment,
                'ppn_mode' => $invoiceRate !== null ? 'INVOICE' : 'PER_ITEM',
                'ppn_rate' => $invoiceRate,
                'dpp_after_invoice_discount' => $dppAfter,
                'ppn_total' => self::round($ppnSum),
                'grand_total' => $grandTotal,
                'inventory_cost_total' => self::round($inventoryCostTotal),
            ],
            '_costing' => $costingByLine,
        ];
    }

    /**
     * Posts the entry. MUST be called inside Database::transaction() so all
     * rows commit or none do. Returns the per-row transaction ids.
     */
    public static function post(PDO $pdo, array $in, int $userId, string $username): array
    {
        assert_required_fields($in, ['transaction_uuid', 'warehouse_id', 'transaction_date', 'lines']);
        $uuid = (string) $in['transaction_uuid'];
        if (strlen($uuid) < 8 || strlen($uuid) > 64) {
            throw new ValidationException(['transaction_uuid must be 8-64 characters']);
        }

        $quote = self::quote($pdo, $in);
        if (!$quote['valid']) {
            throw new ValidationException($quote['errors']);
        }

        $supplierId = isset($in['supplier_id']) && $in['supplier_id'] !== '' ? (int) $in['supplier_id'] : null;
        $reference = isset($in['reference_no']) && trim((string) $in['reference_no']) !== '' ? trim((string) $in['reference_no']) : null;
        $notes = isset($in['notes']) && trim((string) $in['notes']) !== '' ? mb_substr(trim((string) $in['notes']), 0, 255) : null;
        $approvedBy = !empty($in['anomaly_approved_by']) ? $userId : null;

        $results = [];
        $anyReplay = false;
        foreach ($quote['lines'] as $i => $row) {
            $costing = $quote['_costing'][$i];
            $payload = [
                'transaction_uuid' => "{$uuid}:IN:{$row['line_no']}",
                'item_id' => $row['item_id'], 'warehouse_id' => (int) $in['warehouse_id'],
                'input_qty' => $row['input_qty'], 'input_unit_id' => $row['input_unit_id'],
                'unit_price_input' => $costing['equivalent_unit_price_input'],
                'transaction_date' => $in['transaction_date'], 'reference_no' => $reference, 'supplier_id' => $supplierId,
                'created_by' => $userId, 'username' => $username,
            ];
            if ($approvedBy !== null) {
                $payload['anomaly_approved_by'] = $approvedBy;
                $payload['anomaly_reason'] = 'Disetujui operator pada Stock IN V2';
            }
            $posted = FifoService::postIn($pdo, $payload);
            if (!empty($posted['idempotent_replay'])) {
                $anyReplay = true;
                $results[] = ['line_no' => $row['line_no'], 'transaction_id' => $posted['transaction_id']];
                continue;
            }
            PurchaseCostingGateway::persist($pdo, $posted, $userId, $costing);
            if ($notes !== null) {
                $pdo->prepare('UPDATE inventory_transaction_lines SET notes = :n WHERE id = :id')->execute(['n' => $notes, 'id' => $posted['line_id']]);
            }
            $results[] = [
                'line_no' => $row['line_no'], 'transaction_id' => $posted['transaction_id'], 'line_id' => $posted['line_id'],
                'batch_id' => $posted['batch_id'], 'base_qty' => $posted['base_qty'], 'unit_cost_base' => $posted['unit_cost_base'],
            ];
        }

        if (!$anyReplay) {
            $t = $quote['totals'];
            AuditService::log(
                $pdo, $userId, $username, 'PURCHASE_INVOICE_POST', 'purchase_invoice', (int) $results[0]['transaction_id'], null,
                [
                    'request_uuid' => $uuid, 'reference_no' => $reference, 'supplier_id' => $supplierId,
                    'transaction_ids' => array_column($results, 'transaction_id'), 'items' => $t['total_items'],
                    'subtotal' => $t['subtotal'], 'invoice_discount_type' => $t['invoice_discount_type'],
                    'invoice_discount_value' => $t['invoice_discount_value'], 'invoice_discount' => $t['invoice_discount'],
                    'freight_amount' => $t['freight_amount'], 'freight_treatment' => $t['freight_treatment'],
                    'ppn_treatment' => $t['ppn_treatment'], 'ppn_mode' => $t['ppn_mode'], 'ppn_rate' => $t['ppn_rate'], 'ppn_total' => $t['ppn_total'], 'grand_total' => $t['grand_total'],
                    'inventory_cost_total' => $t['inventory_cost_total'],
                ]
            );
        }

        $totals = $quote['totals'];
        return ['success' => true, 'idempotent_replay' => $anyReplay, 'transactions' => $results, 'totals' => $totals];
    }

    private static function round(float $v): float
    {
        return round($v, self::MONEY_SCALE);
    }
}
