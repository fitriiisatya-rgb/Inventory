<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * STOCK OUT V2 — printable Delivery Order and Invoice (server-rendered A4
 * HTML; the browser's own Print dialog makes the PDF, as the existing
 * Distribusi documents do). ONE renderer per document, fed by a plain "model"
 * array, so the PREVIEW (model built from the sheet's quote) and the saved,
 * re-printable document (model built from the database) go through exactly the
 * same HTML — what you preview is what you print.
 *
 * LEAK GUARD (safety-critical)
 *   DO      → item name / qty / unit only. No price, cost, markup or totals.
 *   Invoice → selling price and totals only. The model builders below SELECT
 *             only the selling columns; reference_purchase_price (Harga
 *             Modal), pricing_method, margin_value, policy_calculated_price,
 *             FIFO cost and category markups are never loaded into the model,
 *             so the renderer cannot print them even by accident.
 *
 * Company: CV AMOR GROUP. Logo: the owner-supplied
 * public/assets/images/amor-logo.jpg, used as-is (only CSS max-size, aspect
 * preserved). No company address/phone/tax number exists in this system, so
 * none is printed.
 */
final class StockOutDocumentService
{
    public const COMPANY = 'CV AMOR GROUP';
    public const TAGLINE = 'Distributor Bahan, Packaging & Perlengkapan Bakery';

    // ------------------------------------------------------------- models
    /** @return array<string,mixed> */
    public static function doModel(PDO $pdo, int $doId): array
    {
        $h = $pdo->prepare(
            'SELECT do.do_number, do.do_date, do.reference_no, do.notes, do.delivery_address_snapshot,
                    w.name AS warehouse_name, bd.name AS bakery_name, bd.address AS bakery_address, bd.pic_name, bd.phone,
                    creator.username AS prepared_by
             FROM distribution_orders do
             JOIN warehouses w ON w.id = do.from_warehouse_id
             JOIN bakery_destinations bd ON bd.id = do.bakery_destination_id
             LEFT JOIN users creator ON creator.id = do.created_by
             WHERE do.id = :id'
        );
        $h->execute(['id' => $doId]);
        $h = $h->fetch();
        if ($h === false) {
            throw new NotFoundException("delivery order {$doId}");
        }
        // NEVER selects any price/cost column.
        $l = $pdo->prepare(
            'SELECT dol.line_no, dol.item_name_snapshot AS name, dol.input_qty AS qty, u.code AS unit
             FROM distribution_order_lines dol JOIN units u ON u.id = dol.input_unit_id
             WHERE dol.do_id = :id ORDER BY dol.line_no'
        );
        $l->execute(['id' => $doId]);
        return [
            'number' => $h['do_number'], 'date' => $h['do_date'], 'reference' => $h['reference_no'], 'notes' => $h['notes'],
            'warehouse' => $h['warehouse_name'], 'bakery' => $h['bakery_name'], 'address' => $h['delivery_address_snapshot'] ?? $h['bakery_address'],
            'pic' => $h['pic_name'], 'phone' => $h['phone'], 'prepared_by' => $h['prepared_by'],
            'lines' => $l->fetchAll(),
        ];
    }

    /** @return array<string,mixed> */
    public static function invoiceModel(PDO $pdo, int $invoiceId): array
    {
        $h = $pdo->prepare(
            'SELECT inv.invoice_number, inv.invoice_date, inv.subtotal, inv.shipping_amount, inv.grand_total,
                    do.reference_no, do.notes, bd.name AS bakery_name, COALESCE(do.delivery_address_snapshot, bd.address) AS address
             FROM distribution_invoices inv
             JOIN distribution_orders do ON do.id = inv.do_id
             JOIN bakery_destinations bd ON bd.id = inv.bakery_destination_id
             WHERE inv.id = :id'
        );
        $h->execute(['id' => $invoiceId]);
        $h = $h->fetch();
        if ($h === false) {
            throw new NotFoundException("invoice {$invoiceId}");
        }
        // NEVER selects reference_purchase_price / pricing_* / margin_value / policy_calculated_price.
        $l = $pdo->prepare(
            'SELECT dil.line_no, dil.item_name_snapshot AS name, dil.qty, u.code AS unit, dil.selling_unit_price AS price, dil.subtotal AS total
             FROM distribution_invoice_lines dil JOIN units u ON u.id = dil.unit_id
             WHERE dil.invoice_id = :id ORDER BY dil.line_no'
        );
        $l->execute(['id' => $invoiceId]);
        return [
            'number' => $h['invoice_number'], 'date' => $h['invoice_date'], 'reference' => $h['reference_no'], 'notes' => $h['notes'],
            'bakery' => $h['bakery_name'], 'address' => $h['address'],
            'lines' => $l->fetchAll(),
            'subtotal' => (float) $h['subtotal'], 'shipping' => (float) $h['shipping_amount'], 'grand_total' => (float) $h['grand_total'],
        ];
    }

    /** Preview model (DO) from a valid StockOutService::quote(). */
    public static function doModelFromQuote(array $quote, array $in, ?string $preparedBy): array
    {
        return [
            'number' => null, 'date' => substr((string) $in['transaction_date'], 0, 10), 'reference' => $in['reference_no'] ?? null, 'notes' => $in['notes'] ?? null,
            'warehouse' => $quote['warehouse']['name'], 'bakery' => $quote['bakery']['name'], 'address' => $quote['bakery']['address'],
            'pic' => $quote['bakery']['pic_name'], 'phone' => $quote['bakery']['phone'], 'prepared_by' => $preparedBy,
            'lines' => array_map(static fn (array $l): array => ['line_no' => $l['line_no'], 'name' => $l['item_name'], 'qty' => $l['input_qty'], 'unit' => $l['unit_code']], $quote['lines']),
        ];
    }

    public static function invoiceModelFromQuote(array $quote, array $in): array
    {
        return [
            'number' => null, 'date' => substr((string) $in['transaction_date'], 0, 10), 'reference' => $in['reference_no'] ?? null, 'notes' => $in['notes'] ?? null,
            'bakery' => $quote['bakery']['name'], 'address' => $quote['bakery']['address'],
            'lines' => array_map(static fn (array $l): array => [
                'line_no' => $l['line_no'], 'name' => $l['item_name'], 'qty' => $l['input_qty'], 'unit' => $l['unit_code'],
                'price' => $l['selling_price'], 'total' => $l['total'],
            ], $quote['lines']),
            'subtotal' => $quote['totals']['subtotal'], 'shipping' => $quote['totals']['shipping_amount'], 'grand_total' => $quote['totals']['grand_total'],
        ];
    }

    // ------------------------------------------------------------ renderers
    public static function renderDo(array $m): string
    {
        $rows = '';
        foreach ($m['lines'] as $l) {
            $rows .= '<tr><td class="c">' . (int) $l['line_no'] . '</td><td>' . self::e($l['name']) . '</td><td class="num">' . self::qty((float) $l['qty']) . '</td><td class="c">' . self::e($l['unit']) . '</td></tr>';
        }
        $dest = '<div class="party-name">' . self::e($m['bakery']) . '</div>'
            . self::line($m['address']) . self::line($m['pic'] !== null && $m['pic'] !== '' ? 'PIC: ' . $m['pic'] : null) . self::line($m['phone'] !== null && $m['phone'] !== '' ? 'Kontak: ' . $m['phone'] : null);
        $body = self::header('DELIVERY ORDER')
            . '<div class="two"><div><div class="lbl">Tujuan</div>' . $dest . '<div class="lbl gap">Gudang Asal</div><div class="party-name">' . self::e($m['warehouse']) . '</div></div>'
            . '<table class="meta">' . self::meta('No. DO', $m['number'] ?? 'Nomor otomatis saat disimpan') . self::meta('Tanggal', self::date($m['date'])) . self::meta('Referensi', $m['reference'] ?? '-') . '</table></div>'
            . '<table class="items"><thead><tr><th class="c w-no">No</th><th>Nama Barang</th><th class="num w-qty">Qty</th><th class="c w-unit">Satuan</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . ($m['notes'] ? '<div class="notes"><b>Catatan:</b> ' . self::e($m['notes']) . '</div>' : '')
            . '<div class="sig">' . self::sig('Disiapkan oleh', $m['prepared_by']) . self::sig('Diterima oleh', null) . '</div>';
        return self::wrap('Delivery Order ' . ($m['number'] ?? ''), $body);
    }

    public static function renderInvoice(array $m): string
    {
        $rows = '';
        foreach ($m['lines'] as $l) {
            $rows .= '<tr><td class="c">' . (int) $l['line_no'] . '</td><td>' . self::e($l['name']) . '</td><td class="num">' . self::qty((float) $l['qty']) . '</td><td class="c">' . self::e($l['unit'])
                . '</td><td class="num">' . self::money((float) $l['price']) . '</td><td class="num">' . self::money((float) $l['total']) . '</td></tr>';
        }
        $bill = '<div class="lbl">Kepada Yth.</div><div class="party-name">' . self::e($m['bakery']) . '</div>' . self::line($m['address']);
        $sum = '<table class="sum"><tr><td>Subtotal Harga Jual</td><td class="num">' . self::money($m['subtotal']) . '</td></tr>'
            . '<tr><td>Biaya Kirim</td><td class="num">' . self::money($m['shipping']) . '</td></tr>'
            . '<tr class="grand"><td>Grand Total</td><td class="num">' . self::money($m['grand_total']) . '</td></tr></table>';
        $notes = '<div class="notes"><b>Catatan:</b><ol><li>Barang dikirim sesuai pesanan dan telah melalui pengecekan.</li><li>Mohon lakukan pengecekan barang saat diterima.</li><li>Hubungi tim kami jika ada ketidaksesuaian.</li></ol>'
            . ($m['notes'] ? '<div>' . self::e($m['notes']) . '</div>' : '') . '</div>';
        $foot = '<div class="foot"><div class="thanks">Terima kasih atas kepercayaan Anda<br><b>' . self::COMPANY . '</b></div>'
            . '<div class="regards">Hormat kami,<br><img src="/assets/images/amor-logo.jpg" alt="" class="logo-sm"><br><b>' . self::COMPANY . '</b></div></div>';
        $body = self::header('INVOICE')
            . '<div class="two"><div>' . $bill . '</div><table class="meta">' . self::meta('No. Invoice', $m['number'] ?? 'Nomor otomatis saat disimpan') . self::meta('Tanggal', self::date($m['date'])) . self::meta('Referensi', $m['reference'] ?? '-') . '</table></div>'
            . '<table class="items"><thead><tr><th class="c w-no">No</th><th>Nama Barang</th><th class="num w-qty">Qty</th><th class="c w-unit">Satuan</th><th class="num w-money">Harga Jual</th><th class="num w-money">Total</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . $sum . $notes . $foot;
        return self::wrap('Invoice ' . ($m['number'] ?? ''), $body);
    }

    // ------------------------------------------------------------- helpers
    private static function header(string $title): string
    {
        return '<div class="head"><img src="/assets/images/amor-logo.jpg" alt="Amor" class="logo"><div class="head-r"><div class="doc-title">' . self::e($title)
            . '</div><div class="co">' . self::COMPANY . '</div><div class="tag">' . self::e(self::TAGLINE) . '</div></div></div>';
    }

    private static function meta(string $k, ?string $v): string
    {
        return '<tr><td class="k">' . self::e($k) . '</td><td class="sep">:</td><td class="v">' . self::e($v ?? '-') . '</td></tr>';
    }

    private static function line(?string $v): string
    {
        return $v !== null && trim($v) !== '' ? '<div class="addr">' . self::e($v) . '</div>' : '';
    }

    private static function sig(string $label, ?string $name): string
    {
        return '<div class="sig-cell"><div class="sig-line"></div><div class="sig-label">' . self::e($label) . '</div><div class="sig-name">' . self::e($name ?? '') . '</div></div>';
    }

    private static function wrap(string $title, string $body): string
    {
        $t = self::e($title);
        return <<<HTML
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{$t}</title>
<style>
@page { size: A4 portrait; margin: 16mm 14mm; }
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; color: #1c2434; background: #fff; margin: 0; padding: 18px 22px; font-size: 12px; line-height: 1.45; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 18px; border-bottom: 2px solid #1c2f5c; padding-bottom: 12px; }
.logo { max-width: 150px; max-height: 80px; width: auto; height: auto; object-fit: contain; }
.logo-sm { max-width: 90px; max-height: 44px; width: auto; height: auto; object-fit: contain; margin: 4px 0; }
.head-r { text-align: right; }
.doc-title { font-size: 26px; font-weight: 800; color: #1c2f5c; letter-spacing: .02em; }
.co { font-size: 15px; font-weight: 700; margin-top: 2px; }
.tag { font-size: 10.5px; color: #5a6478; margin-top: 2px; }
.two { display: flex; justify-content: space-between; gap: 24px; margin-bottom: 14px; }
.lbl { font-size: 11px; color: #5a6478; } .lbl.gap { margin-top: 10px; }
.party-name { font-weight: 700; font-size: 13px; }
.addr { color: #333; }
table.meta { border-collapse: collapse; } table.meta td { padding: 1px 0; vertical-align: top; }
table.meta td.k { color: #5a6478; padding-right: 10px; white-space: nowrap; } table.meta td.sep { padding-right: 8px; } table.meta td.v { font-weight: 700; }
table.items { width: 100%; border-collapse: collapse; margin: 6px 0 14px; }
table.items th { background: #e9eef8; color: #1c2f5c; font-weight: 700; padding: 7px 8px; border: 1px solid #c8d1e4; text-align: left; font-size: 11.5px; }
table.items td { padding: 6px 8px; border: 1px solid #d6dcea; }
.c { text-align: center !important; } .num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
.w-no { width: 36px; } .w-qty { width: 70px; } .w-unit { width: 70px; } .w-money { width: 110px; }
table.sum { margin-left: auto; width: 290px; border-collapse: collapse; }
table.sum td { padding: 4px 8px; } table.sum tr.grand td { background: #e9eef8; color: #1c2f5c; font-weight: 800; font-size: 14px; border-top: 1px solid #1c2f5c; padding: 8px; }
.notes { margin-top: 14px; font-size: 11.5px; } .notes ol { margin: 4px 0 6px 18px; padding: 0; }
.foot { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 26px; }
.regards { text-align: right; }
.sig { display: flex; justify-content: space-around; margin-top: 56px; }
.sig-cell { width: 34%; text-align: center; } .sig-line { border-top: 1px solid #333; height: 46px; } .sig-label { font-weight: 700; margin-top: 4px; } .sig-name { color: #555; min-height: 14px; }
@media print { body { padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style></head><body>{$body}</body></html>
HTML;
    }

    private static function e(?string $v): string
    {
        return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
    }

    private static function qty(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, ',', '.'), '0'), ',') ?: '0';
    }

    private static function money(float $v): string
    {
        $f = number_format($v, 2, ',', '.');
        return 'Rp ' . (str_ends_with($f, ',00') ? substr($f, 0, -3) : $f);
    }

    private static function date(?string $d): string
    {
        if ($d === null || $d === '') {
            return '-';
        }
        $ts = strtotime($d);
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        return $ts === false ? $d : date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }
}
