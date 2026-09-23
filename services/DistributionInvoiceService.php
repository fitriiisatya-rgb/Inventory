<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.11B — Invoice generation from a dispatched Delivery Order.
 *
 * DRAFT -> ISSUED -> (CANCELLED at any point, financial snapshot untouched)
 *
 * create() builds the real invoice row immediately (not an ephemeral
 * preview) with every line's pricing snapshot already resolved and
 * persisted — the DRAFT status itself IS the "review before issue" state
 * (Part 32): an admin reviews it via get(), optionally overrides a line's
 * price via overrideLinePrice(), then calls issue(). Once ISSUED the
 * financial snapshot is immutable (Part 22) — override is refused past
 * that point.
 *
 * Revenue/HPP/margin reporting (Phase V2.11C) reads this table plus the
 * DO line's real out_transaction_line_id — never estimates HPP from the
 * reference price (Part 34).
 */
final class DistributionInvoiceService
{
    private const MONEY_SCALE = 4;

    /** @param array{do_id:int, invoice_date:string, created_by:int, username?:string, discount_amount?:float, tax_amount?:float, shipping_amount?:float} $p */
    public static function create(PDO $pdo, array $p): array
    {
        assert_required_fields($p, ['do_id', 'invoice_date', 'created_by']);
        $doId = (int) $p['do_id'];

        $do = $pdo->prepare('SELECT * FROM distribution_orders WHERE id = :id');
        $do->execute(['id' => $doId]);
        $do = $do->fetch();
        if ($do === false) {
            throw new NotFoundException("distribution order {$doId}");
        }
        // Only once real stock has actually left SCM (dispatch() sets
        // qty_sent_base) is there a real, priceable quantity to invoice —
        // never a DRAFT/APPROVED/PICKING DO's merely-planned qty.
        if (!in_array($do['status'], ['DISPATCHED', 'RECEIVED', 'RECEIVED_WITH_DISCREPANCY', 'COMPLETED'], true)) {
            throw new ValidationException(["distribution order {$do['do_number']} is {$do['status']} — an Invoice can only be created once it has been dispatched"]);
        }

        $existing = $pdo->prepare('SELECT id FROM distribution_invoices WHERE do_id = :id');
        $existing->execute(['id' => $doId]);
        if ($existing->fetchColumn() !== false) {
            throw new ValidationException(["distribution order {$do['do_number']} already has an Invoice — each DO may only generate one"]);
        }

        $lines = $pdo->prepare('SELECT * FROM distribution_order_lines WHERE do_id = :id ORDER BY line_no');
        $lines->execute(['id' => $doId]);
        $lines = $lines->fetchAll();

        $invoiceNumber = NumberingService::next($pdo, 'INV', $p['invoice_date']);
        $now = date('Y-m-d H:i:s');

        $header = $pdo->prepare(
            'INSERT INTO distribution_invoices
                (invoice_number, invoice_date, do_id, bakery_destination_id, discount_amount, tax_amount, shipping_amount, status, created_by, created_at)
             VALUES (:num, :date, :do_id, :bakery, :discount, :tax, :shipping, \'DRAFT\', :created_by, :now)'
        );
        $discount = round((float) ($p['discount_amount'] ?? 0), self::MONEY_SCALE);
        $tax = round((float) ($p['tax_amount'] ?? 0), self::MONEY_SCALE);
        $shipping = round((float) ($p['shipping_amount'] ?? 0), self::MONEY_SCALE);
        $header->execute([
            'num' => $invoiceNumber, 'date' => $p['invoice_date'], 'do_id' => $doId,
            'bakery' => $do['bakery_destination_id'], 'discount' => $discount, 'tax' => $tax, 'shipping' => $shipping,
            'created_by' => $p['created_by'], 'now' => $now,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $lineStmt = $pdo->prepare(
            'INSERT INTO distribution_invoice_lines
                (invoice_id, do_line_id, line_no, item_id, sku_snapshot, item_name_snapshot, category_id_snapshot,
                 qty, unit_id, reference_purchase_price, pricing_source, pricing_method, margin_value,
                 policy_calculated_price, selling_unit_price, subtotal, created_at)
             VALUES (:invoice_id, :do_line_id, :line_no, :item_id, :sku, :name, :category,
                     :qty, :unit, :ref_price, :source, :method, :margin,
                     :calc_price, :selling_price, :subtotal, :now)'
        );

        $subtotal = 0.0;
        $lineNo = 1;
        foreach ($lines as $line) {
            $itemId = (int) $line['item_id'];
            $unitId = (int) $line['input_unit_id'];
            $qty = (float) $line['input_qty'];

            $refPrice = ItemPriceService::resolveReferencePrice($pdo, $itemId, $unitId);
            if ($refPrice['reference_price'] === null) {
                throw new ValidationException(["item {$line['sku_snapshot']}: belum ada harga referensi database — tidak dapat membuat invoice untuk baris ini"]);
            }

            $policy = PricingPolicyService::resolve($pdo, $itemId, $line['category_id_snapshot'] !== null ? (int) $line['category_id_snapshot'] : null);
            if ($policy === null) {
                throw new ValidationException(["item {$line['sku_snapshot']}: tidak ada pricing policy (SKU/Kategori/Perusahaan) yang aktif — tidak dapat membuat invoice untuk baris ini"]);
            }

            $sellingPrice = PricingPolicyService::calculateSellingPrice((float) $refPrice['reference_price'], $policy['pricing_method'], $policy['margin_value']);
            $lineSubtotal = round($qty * $sellingPrice, self::MONEY_SCALE);
            $subtotal += $lineSubtotal;

            $lineStmt->execute([
                'invoice_id' => $invoiceId, 'do_line_id' => $line['id'], 'line_no' => $lineNo,
                'item_id' => $itemId, 'sku' => $line['sku_snapshot'], 'name' => $line['item_name_snapshot'],
                'category' => $line['category_id_snapshot'], 'qty' => $qty, 'unit' => $unitId,
                'ref_price' => $refPrice['reference_price'], 'source' => $policy['scope'], 'method' => $policy['pricing_method'],
                'margin' => $policy['margin_value'], 'calc_price' => $sellingPrice, 'selling_price' => $sellingPrice,
                'subtotal' => $lineSubtotal, 'now' => $now,
            ]);
            $lineNo++;
        }

        $subtotal = round($subtotal, self::MONEY_SCALE);
        $grandTotal = round($subtotal - $discount + $tax + $shipping, self::MONEY_SCALE);
        $pdo->prepare('UPDATE distribution_invoices SET subtotal = :subtotal, grand_total = :grand_total WHERE id = :id')
            ->execute(['subtotal' => $subtotal, 'grand_total' => $grandTotal, 'id' => $invoiceId]);

        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_INVOICE_CREATE',
            'distribution_invoices', $invoiceId, null,
            ['invoice_number' => $invoiceNumber, 'do_id' => $doId, 'subtotal' => $subtotal, 'grand_total' => $grandTotal],
            null
        );

        return ['success' => true, 'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber];
    }

    public static function issue(PDO $pdo, int $invoiceId, array $p): array
    {
        $invoice = self::find($pdo, $invoiceId);
        if ($invoice['status'] !== 'DRAFT') {
            throw new ValidationException(["invoice {$invoice['invoice_number']} is {$invoice['status']}, must be DRAFT before it can be issued"]);
        }

        $pdo->prepare('UPDATE distribution_invoices SET status = \'ISSUED\', issued_by = :by, issued_at = :now WHERE id = :id')
            ->execute(['by' => $p['created_by'], 'now' => date('Y-m-d H:i:s'), 'id' => $invoiceId]);

        AuditService::log($pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_INVOICE_ISSUE', 'distribution_invoices', $invoiceId, ['status' => 'DRAFT'], ['status' => 'ISSUED'], null);

        return ['success' => true, 'invoice_id' => $invoiceId];
    }

    public static function cancel(PDO $pdo, int $invoiceId, array $p): array
    {
        $invoice = self::find($pdo, $invoiceId);
        if ($invoice['status'] === 'CANCELLED') {
            throw new ValidationException(["invoice {$invoice['invoice_number']} is already cancelled"]);
        }
        $reason = trim((string) ($p['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw new ValidationException(['a cancellation reason of at least 5 characters is required']);
        }

        $pdo->prepare('UPDATE distribution_invoices SET status = \'CANCELLED\', cancel_reason = :reason, cancelled_by = :by, cancelled_at = :now WHERE id = :id')
            ->execute(['reason' => $reason, 'by' => $p['created_by'], 'now' => date('Y-m-d H:i:s'), 'id' => $invoiceId]);

        AuditService::log($pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_INVOICE_CANCEL', 'distribution_invoices', $invoiceId, ['status' => $invoice['status']], ['status' => 'CANCELLED'], $reason);

        return ['success' => true, 'invoice_id' => $invoiceId];
    }

    /**
     * Admin override of one line's selling price (Part 33). Only while the
     * invoice is still DRAFT — an ISSUED invoice's financial snapshot is
     * immutable (Part 22). Never modifies the underlying
     * distribution_pricing_policies row: overriding one invoice's price
     * is a one-off, not a policy change.
     */
    public static function overrideLinePrice(PDO $pdo, int $invoiceId, int $lineId, array $p): array
    {
        $invoice = self::find($pdo, $invoiceId);
        if ($invoice['status'] !== 'DRAFT') {
            throw new ValidationException(["invoice {$invoice['invoice_number']} is {$invoice['status']} — price can only be overridden while DRAFT"]);
        }

        $line = $pdo->prepare('SELECT * FROM distribution_invoice_lines WHERE id = :id AND invoice_id = :invoice_id');
        $line->execute(['id' => $lineId, 'invoice_id' => $invoiceId]);
        $line = $line->fetch();
        if ($line === false) {
            throw new NotFoundException("invoice line {$lineId}");
        }

        $newPrice = round((float) ($p['selling_unit_price'] ?? -1), self::MONEY_SCALE);
        if ($newPrice < 0) {
            throw new ValidationException(['selling_unit_price must be >= 0']);
        }
        $reason = trim((string) ($p['override_reason'] ?? ''));
        if ($reason === '') {
            throw new ValidationException(['override_reason is required when overriding a price']);
        }

        $newSubtotal = round((float) $line['qty'] * $newPrice, self::MONEY_SCALE);
        $pdo->prepare(
            'UPDATE distribution_invoice_lines
             SET selling_unit_price = :price, subtotal = :subtotal, is_price_overridden = 1, override_reason = :reason, override_by = :by
             WHERE id = :id'
        )->execute(['price' => $newPrice, 'subtotal' => $newSubtotal, 'reason' => $reason, 'by' => $p['created_by'], 'id' => $lineId]);

        self::recalculateHeaderTotals($pdo, $invoiceId);

        AuditService::log(
            $pdo, $p['created_by'], $p['username'] ?? 'system', 'DISTRIBUTION_INVOICE_LINE_OVERRIDE',
            'distribution_invoice_lines', $lineId,
            ['selling_unit_price' => (float) $line['selling_unit_price']], ['selling_unit_price' => $newPrice], $reason
        );

        return ['success' => true, 'invoice_id' => $invoiceId, 'line_id' => $lineId];
    }

    public static function get(PDO $pdo, int $invoiceId): array
    {
        $invoice = self::find($pdo, $invoiceId);
        $lines = $pdo->prepare(
            'SELECT l.*, u.code AS unit_code, u.name AS unit_name
             FROM distribution_invoice_lines l JOIN units u ON u.id = l.unit_id
             WHERE l.invoice_id = :id ORDER BY l.line_no'
        );
        $lines->execute(['id' => $invoiceId]);
        $invoice['lines'] = $lines->fetchAll();
        return $invoice;
    }

    public static function listAll(PDO $pdo, array $filters = []): array
    {
        $sql = 'SELECT inv.*, bd.name AS bakery_name, do.do_number
                FROM distribution_invoices inv
                JOIN bakery_destinations bd ON bd.id = inv.bakery_destination_id
                JOIN distribution_orders do ON do.id = inv.do_id
                WHERE 1=1';
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= ' AND inv.status = :status';
            $params['status'] = $filters['status'];
        }
        $sql .= ' ORDER BY inv.created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private static function recalculateHeaderTotals(PDO $pdo, int $invoiceId): void
    {
        $subtotal = (float) $pdo->query('SELECT COALESCE(SUM(subtotal), 0) FROM distribution_invoice_lines WHERE invoice_id = ' . (int) $invoiceId)->fetchColumn();
        $invoice = $pdo->prepare('SELECT discount_amount, tax_amount, shipping_amount FROM distribution_invoices WHERE id = :id');
        $invoice->execute(['id' => $invoiceId]);
        $invoice = $invoice->fetch();
        $grandTotal = round($subtotal - (float) $invoice['discount_amount'] + (float) $invoice['tax_amount'] + (float) $invoice['shipping_amount'], self::MONEY_SCALE);

        $pdo->prepare('UPDATE distribution_invoices SET subtotal = :subtotal, grand_total = :grand_total WHERE id = :id')
            ->execute(['subtotal' => round($subtotal, self::MONEY_SCALE), 'grand_total' => $grandTotal, 'id' => $invoiceId]);
    }

    private static function find(PDO $pdo, int $invoiceId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM distribution_invoices WHERE id = :id');
        $stmt->execute(['id' => $invoiceId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException("distribution invoice {$invoiceId}");
        }
        return $row;
    }
}
