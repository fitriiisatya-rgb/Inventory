<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * STOCK OUT V2 — table-first issue of goods to a bakery destination that
 * produces a Delivery Order (DO) and an Invoice in ONE atomic action.
 *
 * NO NEW STORAGE. Everything lands in tables the Distribusi Bakery module
 * (V2.11) already owns and that DistributionPrintService / reports already
 * read: distribution_orders (+lines) for the DO, distribution_invoices
 * (+lines) for the Invoice, document_number_sequences (NumberingService) for
 * DO-/INV- numbers, and the unmodified FifoService::postOut() for the stock
 * movement. A Stock OUT V2 DO is created directly in status DISPATCHED (goods
 * leave at save time — there is no separate picking step on this screen) and
 * its Invoice directly ISSUED; the bakery can still receive it through the
 * existing DO "receive" flow. Unlike the SCM-only distribution_orders
 * create(), the origin warehouse is any warehouse the user is authorised for.
 *
 * COST vs SELLING PRICE (never mixed)
 *   - Inventory: FifoService::postOut() consumes the real FIFO layers and
 *     records the real HPP on inventory_transaction_lines.unit_cost_base —
 *     selling price never reaches it.
 *   - "Harga Modal" (the base the markup is applied to) is the REFERENCE
 *     PURCHASE PRICE of the selected unit = ItemPriceService (latest real
 *     purchase from item_price_history, exact unit first, otherwise derived
 *     through the base-unit price and the approved conversion). It is the same
 *     source Stock IN's default "Harga Beli" and the existing Invoice module
 *     use, deliberately NOT the FIFO/HPP cost (a batch may hold several costs
 *     and HPP is internal) and not a markup of an average.
 *   - Harga Jual = Harga Modal + Harga Modal x % (COST_PLUS_PERCENT), or
 *     Harga Modal + Rp (COST_PLUS_AMOUNT, per selected unit); Total = Qty x
 *     Harga Jual; Grand Total = SUM(Total) + Biaya Kirim. No PPN, no discount.
 *   - The Invoice rows store the cost basis (reference_purchase_price) and the
 *     selling basis (method/margin/selling_unit_price) side by side; printed
 *     documents never select the former (see StockOutDocumentService).
 */
final class StockOutService
{
    private const MONEY_SCALE = 4;
    private const MAX_LINES = 200;

    /** Active category/company policy → default markup for the sheet (never silently applied by the server). */
    public static function markupDefaults(PDO $pdo, array $categoryIds): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $categoryIds)) as $cid) {
            $policy = PricingPolicyService::resolve($pdo, 0, $cid > 0 ? $cid : null);
            if ($policy === null) {
                continue;
            }
            $mode = $policy['pricing_method'] === 'COST_PLUS_AMOUNT' ? 'AMOUNT' : 'PERCENT';
            $value = $policy['pricing_method'] === 'AT_COST' ? 0.0 : (float) $policy['margin_value'];
            $out[(string) $cid] = ['mode' => $mode, 'value' => $value, 'source' => $policy['scope']];
        }
        return $out;
    }

    /** Pure + read-only. @return array<string,mixed> */
    public static function quote(PDO $pdo, array $in): array
    {
        $errors = [];
        $date = (string) ($in['transaction_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            $errors[] = 'transaction_date is required (YYYY-MM-DD)';
        }
        $warehouseId = (int) ($in['warehouse_id'] ?? 0);
        $warehouse = null;
        if ($warehouseId <= 0) {
            $errors[] = 'Gudang Asal wajib dipilih';
        } else {
            $w = $pdo->prepare('SELECT id, code, name, is_active FROM warehouses WHERE id = :id');
            $w->execute(['id' => $warehouseId]);
            $warehouse = $w->fetch() ?: null;
            if ($warehouse === null) {
                $errors[] = "gudang {$warehouseId} tidak ditemukan";
            }
        }
        $bakeryId = (int) ($in['bakery_destination_id'] ?? 0);
        $bakery = null;
        if ($bakeryId <= 0) {
            $errors[] = 'Bakery Tujuan wajib dipilih';
        } else {
            $b = $pdo->prepare('SELECT id, name, address, pic_name, phone, is_active FROM bakery_destinations WHERE id = :id');
            $b->execute(['id' => $bakeryId]);
            $bakery = $b->fetch() ?: null;
            if ($bakery === null || (int) $bakery['is_active'] !== 1) {
                $errors[] = 'Bakery Tujuan tidak ditemukan atau tidak aktif';
                $bakery = null;
            }
        }

        $shipping = self::round((float) ($in['shipping_amount'] ?? 0));
        if ($shipping < 0) {
            $errors[] = 'Biaya Kirim tidak boleh negatif';
            $shipping = 0.0;
        }

        $rawLines = $in['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            $errors[] = 'minimal satu barang harus diisi';
            $rawLines = [];
        }
        if (count($rawLines) > self::MAX_LINES) {
            $errors[] = 'maksimal ' . self::MAX_LINES . ' barang per transaksi';
            $rawLines = array_slice($rawLines, 0, self::MAX_LINES);
        }
        $markups = is_array($in['markups'] ?? null) ? $in['markups'] : [];

        // requested base qty per item (the same item may appear on several rows)
        $needByItem = [];
        $rows = [];
        foreach (array_values($rawLines) as $i => $l) {
            $n = $i + 1;
            $row = ['line_no' => $n, 'errors' => []];
            $itemId = (int) ($l['item_id'] ?? 0);
            $unitId = (int) ($l['input_unit_id'] ?? 0);
            $qty = (float) ($l['input_qty'] ?? 0);
            $item = null;
            if ($itemId <= 0) {
                $row['errors'][] = 'barang belum dipilih';
            } else {
                $st = $pdo->prepare(
                    'SELECT i.id, i.sku, i.name, i.status, i.category_id, c.name AS category_name
                     FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.id = :id'
                );
                $st->execute(['id' => $itemId]);
                $item = $st->fetch() ?: null;
                if ($item === null) {
                    $row['errors'][] = 'barang tidak ditemukan di Master Barang';
                } elseif ($item['status'] !== 'ACTIVE') {
                    $row['errors'][] = 'barang tidak aktif';
                }
            }
            $factor = null;
            $unitCode = null;
            if ($item !== null) {
                if ($unitId <= 0) {
                    $row['errors'][] = 'satuan belum dipilih';
                } else {
                    $factor = UnitConversionService::resolveConversionFactor($pdo, $itemId, $unitId, $date !== '' ? $date : date('Y-m-d'));
                    if ($factor === null) {
                        $row['errors'][] = 'satuan tidak valid / belum disetujui untuk barang ini';
                    } else {
                        $u = $pdo->prepare('SELECT code FROM units WHERE id = :id');
                        $u->execute(['id' => $unitId]);
                        $unitCode = $u->fetchColumn() ?: null;
                    }
                }
            }
            if (!($qty > 0)) {
                $row['errors'][] = 'qty harus lebih dari 0';
            }
            $baseQty = $factor !== null ? round($qty * $factor, 6) : null;
            if ($baseQty !== null && $baseQty > 0) {
                $needByItem[$itemId] = ($needByItem[$itemId] ?? 0.0) + $baseQty;
            }

            $row += [
                'item_id' => $itemId, 'item_name' => $item['name'] ?? null, 'sku' => $item['sku'] ?? null,
                'category_id' => $item !== null ? (int) ($item['category_id'] ?? 0) : 0,
                'category_name' => $item['category_name'] ?? null,
                'input_unit_id' => $unitId, 'unit_code' => $unitCode, 'input_qty' => $qty,
                'conversion_factor' => $factor, 'base_qty' => $baseQty,
            ];
            $rows[] = $row;
        }

        // stock sufficiency (the authoritative figure: InventoryService::currentStock)
        $stockByItem = [];
        foreach ($needByItem as $itemId => $need) {
            if ($warehouse === null) {
                break;
            }
            $cs = InventoryService::currentStock($pdo, (int) $itemId, $warehouseId);
            $stockByItem[$itemId] = ['qty' => (float) $cs['qty_base'], 'review' => !empty($cs['migration_negative_review'])];
        }

        $subtotal = 0.0;
        $qtySum = 0.0;
        $units = [];
        foreach ($rows as $i => &$r) {
            $stock = $stockByItem[$r['item_id']] ?? null;
            $r['stock_base'] = $stock['qty'] ?? null;
            $r['stock_in_unit'] = ($stock !== null && $r['conversion_factor'] !== null && $r['conversion_factor'] > 0) ? round($stock['qty'] / $r['conversion_factor'], 6) : null;
            if ($stock !== null && $r['base_qty'] !== null) {
                $need = $needByItem[$r['item_id']];
                if ($stock['review']) {
                    $r['errors'][] = 'barang berstatus MIGRATION_NEGATIVE_REVIEW — selesaikan lewat Stock Opname / Adjustment dahulu';
                } elseif ($need > $stock['qty'] + 0.0000005) {
                    $fmt = static fn (float $v): string => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
                    $r['errors'][] = 'stok tidak cukup: diminta ' . $fmt($r['input_qty']) . ' ' . $r['unit_code'] . ', tersedia ' . $fmt((float) $r['stock_in_unit']) . ' ' . $r['unit_code']
                        . ($need > $r['base_qty'] + 0.0000005 ? ' (baris lain dengan barang yang sama ikut dihitung)' : '');
                }
            }

            // Harga Modal + markup of this row's category
            $r['reference_price'] = null;
            $r['price_source'] = 'NONE';
            $r['markup_mode'] = null;
            $r['markup_value'] = null;
            $r['selling_price'] = null;
            $r['total'] = null;
            if ($r['item_id'] > 0 && $r['conversion_factor'] !== null) {
                $ref = ItemPriceService::resolveReferencePrice($pdo, $r['item_id'], $r['input_unit_id']);
                $r['price_source'] = $ref['price_source'];
                if ($ref['reference_price'] === null) {
                    $r['errors'][] = 'belum ada Harga Modal (harga beli referensi) untuk barang ini — catat pembelian lewat Stock IN dahulu';
                } else {
                    $r['reference_price'] = (float) $ref['reference_price'];
                    $mk = $markups[(string) $r['category_id']] ?? $markups[$r['category_id']] ?? null;
                    if (!is_array($mk) || !isset($mk['mode'], $mk['value']) || $mk['value'] === '' || $mk['value'] === null) {
                        $r['errors'][] = 'markup kategori ' . ($r['category_name'] ?? 'tanpa kategori') . ' belum diisi (isi 0 jika memang tanpa markup)';
                    } else {
                        $mode = (string) $mk['mode'];
                        $val = (float) $mk['value'];
                        if (!in_array($mode, ['PERCENT', 'AMOUNT'], true)) {
                            $r['errors'][] = 'mode markup tidak valid';
                        } elseif ($val < 0 || ($mode === 'PERCENT' && $val > 10000)) {
                            $r['errors'][] = 'nilai markup tidak valid';
                        } else {
                            $r['markup_mode'] = $mode;
                            $r['markup_value'] = $val;
                            $r['selling_price'] = PricingPolicyService::calculateSellingPrice(
                                $r['reference_price'], $mode === 'PERCENT' ? 'COST_PLUS_PERCENT' : 'COST_PLUS_AMOUNT', $val
                            );
                            $r['total'] = self::round($r['input_qty'] * $r['selling_price']);
                            $subtotal += $r['total'];
                        }
                    }
                }
            }
            $qtySum += max(0.0, $r['input_qty']);
            if ($r['unit_code'] !== null) {
                $units[$r['unit_code']] = true;
            }
        }
        unset($r);

        foreach ($rows as $r) {
            foreach ($r['errors'] as $e) {
                $errors[] = "baris {$r['line_no']}: {$e}";
            }
        }
        $subtotal = self::round($subtotal);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warehouse' => $warehouse ? ['id' => (int) $warehouse['id'], 'name' => $warehouse['name']] : null,
            'bakery' => $bakery,
            'lines' => array_map(static function (array $r): array {
                unset($r['conversion_factor']);
                return $r;
            }, $rows),
            'totals' => [
                'total_items' => count($rows),
                'total_qty' => round($qtySum, 6),
                'qty_unit' => count($units) === 1 ? (string) array_key_first($units) : null,
                'subtotal' => $subtotal,
                'shipping_amount' => $shipping,
                'grand_total' => self::round($subtotal + $shipping),
            ],
        ];
    }

    /** MUST run inside Database::transaction(). */
    public static function post(PDO $pdo, array $in, int $userId, string $username): array
    {
        assert_required_fields($in, ['transaction_uuid', 'warehouse_id', 'bakery_destination_id', 'transaction_date', 'lines']);
        $uuid = (string) $in['transaction_uuid'];
        if (strlen($uuid) < 8 || strlen($uuid) > 64) {
            throw new ValidationException(['transaction_uuid must be 8-64 characters']);
        }

        // idempotent replay (same request uuid → the DO it already created)
        $dup = $pdo->prepare('SELECT id FROM distribution_orders WHERE dispatch_request_uuid = :u');
        $dup->execute(['u' => $uuid]);
        if (($existingId = $dup->fetchColumn()) !== false) {
            return ['success' => true, 'idempotent_replay' => true] + self::summary($pdo, (int) $existingId);
        }

        $quote = self::quote($pdo, $in);
        if (!$quote['valid']) {
            throw new ValidationException($quote['errors']);
        }
        WarehouseGuardService::assertActive($pdo, (int) $in['warehouse_id']);

        $date = substr((string) $in['transaction_date'], 0, 10);
        $bakery = $quote['bakery'];
        $reference = isset($in['reference_no']) && trim((string) $in['reference_no']) !== '' ? mb_substr(trim((string) $in['reference_no']), 0, 100) : null;
        $notes = isset($in['notes']) && trim((string) $in['notes']) !== '' ? mb_substr(trim((string) $in['notes']), 0, 255) : null;
        $now = date('Y-m-d H:i:s');

        $doNumber = NumberingService::next($pdo, 'DO', $date);
        $pdo->prepare(
            'INSERT INTO distribution_orders
                (do_number, do_date, from_warehouse_id, bakery_destination_id, delivery_address_snapshot, reference_no, notes,
                 status, created_by, approved_by, approved_at, picking_started_by, picking_started_at,
                 dispatched_by, dispatched_at, dispatch_request_uuid, created_at)
             VALUES (:no, :d, :wh, :b, :addr, :ref, :notes, \'DISPATCHED\', :u1, :u2, :n1, :u3, :n2, :u4, :n3, :req, :n4)'
        )->execute([
            'no' => $doNumber, 'd' => $date, 'wh' => (int) $in['warehouse_id'], 'b' => (int) $bakery['id'],
            'addr' => $bakery['address'], 'ref' => $reference, 'notes' => $notes, 'req' => $uuid,
            'u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId, 'n1' => $now, 'n2' => $now, 'n3' => $now, 'n4' => $now,
        ]);
        $doId = (int) $pdo->lastInsertId();

        $invoiceNumber = NumberingService::next($pdo, 'INV', $date);
        $pdo->prepare(
            'INSERT INTO distribution_invoices
                (invoice_number, invoice_date, do_id, bakery_destination_id, subtotal, discount_amount, tax_amount, shipping_amount, grand_total,
                 status, created_by, issued_by, issued_at, created_at)
             VALUES (:no, :d, :do, :b, :sub, 0, 0, :ship, :grand, \'ISSUED\', :u1, :u2, :n1, :n2)'
        )->execute([
            'no' => $invoiceNumber, 'd' => $date, 'do' => $doId, 'b' => (int) $bakery['id'],
            'sub' => $quote['totals']['subtotal'], 'ship' => $quote['totals']['shipping_amount'], 'grand' => $quote['totals']['grand_total'],
            'u1' => $userId, 'u2' => $userId, 'n1' => $now, 'n2' => $now,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $doLine = $pdo->prepare(
            'INSERT INTO distribution_order_lines
                (do_id, line_no, item_id, sku_snapshot, item_name_snapshot, category_id_snapshot, input_qty, input_unit_id, qty_base, created_at)
             VALUES (:do, :n, :item, :sku, :name, :cat, :qty, :unit, :qb, :now)'
        );
        $invLine = $pdo->prepare(
            'INSERT INTO distribution_invoice_lines
                (invoice_id, do_line_id, line_no, item_id, sku_snapshot, item_name_snapshot, category_id_snapshot, qty, unit_id,
                 reference_purchase_price, pricing_source, pricing_method, margin_value, policy_calculated_price, selling_unit_price, subtotal, created_at)
             VALUES (:inv, :dol, :n, :item, :sku, :name, :cat, :qty, :unit, :ref, \'CATEGORY\', :method, :margin, :sell1, :sell2, :sub, :now)'
        );
        $transactions = [];
        foreach ($quote['lines'] as $l) {
            $catId = $l['category_id'] > 0 ? $l['category_id'] : null;
            $doLine->execute([
                'do' => $doId, 'n' => $l['line_no'], 'item' => $l['item_id'], 'sku' => $l['sku'], 'name' => $l['item_name'], 'cat' => $catId,
                'qty' => $l['input_qty'], 'unit' => $l['input_unit_id'], 'qb' => $l['base_qty'], 'now' => $now,
            ]);
            $doLineId = (int) $pdo->lastInsertId();

            // real FIFO consumption — unmodified engine, selling price never passed in
            $out = FifoService::postOut($pdo, [
                'transaction_uuid' => "{$doNumber}:OUT:{$doLineId}", 'item_id' => $l['item_id'], 'warehouse_id' => (int) $in['warehouse_id'],
                'input_qty' => $l['input_qty'], 'input_unit_id' => $l['input_unit_id'], 'transaction_type' => 'OUT',
                'transaction_date' => $date . ' 00:00:00', 'reference_no' => $doNumber, 'bakery_destination_id' => (int) $bakery['id'],
                'created_by' => $userId, 'username' => $username,
            ]);
            $pdo->prepare('UPDATE distribution_order_lines SET qty_sent_base = :q, out_transaction_line_id = :l WHERE id = :id')
                ->execute(['q' => $out['base_qty'], 'l' => $out['line_id'], 'id' => $doLineId]);

            $invLine->execute([
                'inv' => $invoiceId, 'dol' => $doLineId, 'n' => $l['line_no'], 'item' => $l['item_id'], 'sku' => $l['sku'], 'name' => $l['item_name'],
                'cat' => $catId, 'qty' => $l['input_qty'], 'unit' => $l['input_unit_id'], 'ref' => $l['reference_price'],
                'method' => $l['markup_mode'] === 'PERCENT' ? 'COST_PLUS_PERCENT' : 'COST_PLUS_AMOUNT', 'margin' => $l['markup_value'],
                'sell1' => $l['selling_price'], 'sell2' => $l['selling_price'], 'sub' => $l['total'], 'now' => $now,
            ]);
            $transactions[] = ['line_no' => $l['line_no'], 'transaction_id' => $out['transaction_id'], 'base_qty' => $out['base_qty']];
        }

        AuditService::log(
            $pdo, $userId, $username, 'STOCK_OUT_V2_POST', 'distribution_orders', $doId, null,
            [
                'do_number' => $doNumber, 'invoice_number' => $invoiceNumber, 'warehouse_id' => (int) $in['warehouse_id'],
                'bakery_destination_id' => (int) $bakery['id'], 'items' => count($quote['lines']),
                'subtotal' => $quote['totals']['subtotal'], 'shipping_amount' => $quote['totals']['shipping_amount'],
                'grand_total' => $quote['totals']['grand_total'], 'markups' => $in['markups'] ?? [],
            ]
        );

        return ['success' => true, 'idempotent_replay' => false] + self::summary($pdo, $doId) + ['transactions' => $transactions];
    }

    /** DO + Invoice identity, totals and bakery — used by the Selesai step, history and reprint. */
    public static function summary(PDO $pdo, int $doId): array
    {
        $s = $pdo->prepare(
            'SELECT do.id AS do_id, do.do_number, do.do_date, do.reference_no, do.notes, do.status AS do_status, do.from_warehouse_id,
                    w.name AS warehouse_name, bd.id AS bakery_id, bd.name AS bakery_name,
                    inv.id AS invoice_id, inv.invoice_number, inv.status AS invoice_status, inv.subtotal, inv.shipping_amount, inv.grand_total,
                    (SELECT COUNT(*) FROM distribution_order_lines l WHERE l.do_id = do.id) AS item_count
             FROM distribution_orders do
             JOIN warehouses w ON w.id = do.from_warehouse_id
             JOIN bakery_destinations bd ON bd.id = do.bakery_destination_id
             LEFT JOIN distribution_invoices inv ON inv.do_id = do.id
             WHERE do.id = :id'
        );
        $s->execute(['id' => $doId]);
        $row = $s->fetch();
        if ($row === false) {
            throw new NotFoundException("delivery order {$doId}");
        }
        return $row;
    }

    /** Recent Stock OUT V2 documents (optionally one warehouse) for the "Riwayat" panel. */
    public static function recent(PDO $pdo, ?int $warehouseId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT do.id AS do_id, do.do_number, do.do_date, do.reference_no, w.name AS warehouse_name, bd.name AS bakery_name,
                       inv.id AS invoice_id, inv.invoice_number, inv.grand_total,
                       (SELECT COUNT(*) FROM distribution_order_lines l WHERE l.do_id = do.id) AS item_count
                FROM distribution_orders do
                JOIN warehouses w ON w.id = do.from_warehouse_id
                JOIN bakery_destinations bd ON bd.id = do.bakery_destination_id
                LEFT JOIN distribution_invoices inv ON inv.do_id = do.id
                WHERE do.status <> \'CANCELLED\'' . ($warehouseId !== null ? ' AND do.from_warehouse_id = :wh' : '') . '
                ORDER BY do.id DESC LIMIT ' . $limit;
        $st = $pdo->prepare($sql);
        $st->execute($warehouseId !== null ? ['wh' => $warehouseId] : []);
        return $st->fetchAll();
    }

    /** Inventory OUT transaction → the DO it belongs to (History Transaksi → reprint). */
    public static function byTransaction(PDO $pdo, int $transactionId): ?array
    {
        $t = $pdo->prepare("SELECT reference_no FROM inventory_transactions WHERE id = :id AND transaction_type = 'OUT'");
        $t->execute(['id' => $transactionId]);
        $ref = $t->fetchColumn();
        if ($ref === false || $ref === null) {
            return null;
        }
        $d = $pdo->prepare('SELECT id FROM distribution_orders WHERE do_number = :n');
        $d->execute(['n' => $ref]);
        $id = $d->fetchColumn();
        return $id === false ? null : self::summary($pdo, (int) $id);
    }

    private static function round(float $v): float
    {
        return round($v, self::MONEY_SCALE);
    }
}
