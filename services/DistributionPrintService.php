<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.11C — clean, minimal A4-print HTML for the Delivery Order
 * (Surat Jalan) and the Invoice. Server-rendered, print-friendly HTML/CSS
 * (Part 20) — no PDF engine exists in this project and none is added
 * here, per the owner's explicit "don't add a large PDF library
 * unnecessarily" instruction; the browser's own Print dialog produces the
 * PDF when the user wants one.
 *
 * LOGO: the official AMOR logo, provided by the owner, is used exactly as
 * supplied — public/assets/images/amor-logo.jpg — never redrawn,
 * regenerated, or resized out of aspect ratio (only CSS max-width/height
 * with width:auto/height:auto, which scales proportionally). No company
 * address/contact fields exist anywhere in system_settings or config —
 * only verified, already-stored fields (warehouse name, bakery name/
 * address) are printed; nothing about a company address/phone/email/tax
 * number is invented, and printing is never blocked merely because that
 * profile doesn't exist.
 *
 * STRICT CONTENT SEPARATION (Part 18/19, safety-critical): the DO
 * document NEVER includes price/HPP/margin/pricing-policy data. The
 * Invoice document NEVER includes FIFO HPP, reference purchase cost,
 * configured margin, or actual margin/profitability. Each render*()
 * method below only SELECTs the columns its own document is allowed to
 * show — neither method has access to the other document's forbidden
 * fields even by accident.
 */
final class DistributionPrintService
{
    public static function renderDeliveryOrder(PDO $pdo, int $doId): string
    {
        $header = $pdo->prepare(
            'SELECT do.*, w.name AS from_warehouse_name, w.code AS from_warehouse_code,
                    bd.name AS bakery_name, bd.address AS bakery_address,
                    creator.username AS created_by_name, dispatcher.username AS dispatched_by_name, receiver.username AS received_by_name
             FROM distribution_orders do
             JOIN warehouses w ON w.id = do.from_warehouse_id
             JOIN bakery_destinations bd ON bd.id = do.bakery_destination_id
             LEFT JOIN users creator ON creator.id = do.created_by
             LEFT JOIN users dispatcher ON dispatcher.id = do.dispatched_by
             LEFT JOIN users receiver ON receiver.id = do.received_by
             WHERE do.id = :id'
        );
        $header->execute(['id' => $doId]);
        $header = $header->fetch();
        if ($header === false) {
            throw new NotFoundException("distribution order {$doId}");
        }

        // NEVER selects price/cost columns — a DO is an operational
        // shipping document only (Part 18).
        $lines = $pdo->prepare(
            'SELECT dol.line_no, dol.sku_snapshot, dol.item_name_snapshot, c.name AS category_name,
                    dol.input_qty, u.code AS unit_code, dol.qty_base,
                    dol.qty_sent_base, dol.qty_received_base, dol.difference_qty_base, dol.discrepancy_reason, dol.notes
             FROM distribution_order_lines dol
             JOIN units u ON u.id = dol.input_unit_id
             LEFT JOIN categories c ON c.id = dol.category_id_snapshot
             WHERE dol.do_id = :id ORDER BY dol.line_no'
        );
        $lines->execute(['id' => $doId]);
        $lines = $lines->fetchAll();

        $rows = '';
        foreach ($lines as $l) {
            // qty_received_base/difference are stored in base unit — for a
            // document meant to be read in the SAME unit the goods were
            // actually shipped in, convert back via this line's own
            // qty_base/input_qty ratio (a presentation-only derivation,
            // never a second source of truth for the real base-unit figures).
            $factor = (float) $l['input_qty'] > 0 ? (float) $l['qty_base'] / (float) $l['input_qty'] : 1.0;
            $qtyReceivedDisplay = $l['qty_received_base'] !== null ? self::fmt((float) $l['qty_received_base'] / $factor) : '-';
            $selisihDisplay = $l['difference_qty_base'] !== null ? self::fmt((float) $l['difference_qty_base'] / $factor) : '-';
            $catatan = $l['discrepancy_reason'] ?? ($l['notes'] ?? '-');

            $rows .= '<tr>'
                . '<td>' . (int) $l['line_no'] . '</td>'
                . '<td>' . self::esc($l['sku_snapshot']) . '</td>'
                . '<td>' . self::esc($l['item_name_snapshot']) . '</td>'
                . '<td>' . self::esc($l['category_name'] ?? '-') . '</td>'
                . '<td class="num">' . self::fmt((float) $l['input_qty']) . '</td>'
                . '<td>' . self::esc($l['unit_code']) . '</td>'
                . '<td class="num">' . $qtyReceivedDisplay . '</td>'
                . '<td class="num">' . $selisihDisplay . '</td>'
                . '<td>' . self::esc((string) $catatan) . '</td>'
                . '</tr>';
        }

        $title = "Surat Jalan {$header['do_number']}";
        $body = self::docHeader('DELIVERY ORDER / SURAT JALAN', [
            ['No. DO', $header['do_number']],
            ['Tanggal', $header['do_date']],
            ['Dari', "Gudang Besar / {$header['from_warehouse_code']} ({$header['from_warehouse_name']})"],
            ['Tujuan Bakery', $header['bakery_name']],
            ['Alamat Tujuan', $header['delivery_address_snapshot'] ?? $header['bakery_address'] ?? '-'],
            ['Referensi', $header['reference_no'] ?? '-'],
        ]);
        $body .= '<table class="doc-table"><thead><tr>'
            . '<th>No</th><th>SKU</th><th>Nama Barang</th><th>Kategori</th><th>Qty Kirim</th><th>Satuan</th><th>Qty Terima</th><th>Selisih</th><th>Catatan</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
        $body .= '<div class="doc-notes"><b>Catatan:</b> ' . self::esc($header['delivery_notes'] ?? '-') . '</div>';
        $body .= self::signatureBlock([
            ['Disiapkan Oleh', $header['created_by_name'] ?? '-'],
            ['Dikirim Oleh', $header['dispatched_by_name'] ?? '-'],
            ['Diterima Bakery', $header['received_by_name'] ?? '-'],
        ]);

        return self::wrapDocument($title, $body);
    }

    public static function renderInvoice(PDO $pdo, int $invoiceId): string
    {
        $header = $pdo->prepare(
            'SELECT inv.*, do.do_number, w.name AS from_warehouse_name, w.code AS from_warehouse_code,
                    bd.name AS bakery_name, bd.address AS bakery_address,
                    creator.username AS created_by_name, issuer.username AS issued_by_name
             FROM distribution_invoices inv
             JOIN distribution_orders do ON do.id = inv.do_id
             JOIN warehouses w ON w.id = do.from_warehouse_id
             JOIN bakery_destinations bd ON bd.id = inv.bakery_destination_id
             LEFT JOIN users creator ON creator.id = inv.created_by
             LEFT JOIN users issuer ON issuer.id = inv.issued_by
             WHERE inv.id = :id'
        );
        $header->execute(['id' => $invoiceId]);
        $header = $header->fetch();
        if ($header === false) {
            throw new NotFoundException("distribution invoice {$invoiceId}");
        }

        // NEVER selects reference_purchase_price/pricing_source/
        // pricing_method/margin_value — a printed Invoice must not expose
        // internal cost/margin data to the Bakery (Part 19).
        $lines = $pdo->prepare(
            'SELECT dil.line_no, dil.sku_snapshot, dil.item_name_snapshot, c.name AS category_name,
                    dil.qty, u.code AS unit_code, dil.selling_unit_price, dil.subtotal
             FROM distribution_invoice_lines dil
             JOIN units u ON u.id = dil.unit_id
             LEFT JOIN categories c ON c.id = dil.category_id_snapshot
             WHERE dil.invoice_id = :id ORDER BY dil.line_no'
        );
        $lines->execute(['id' => $invoiceId]);
        $lines = $lines->fetchAll();

        $rows = '';
        foreach ($lines as $l) {
            $rows .= '<tr>'
                . '<td>' . (int) $l['line_no'] . '</td>'
                . '<td>' . self::esc($l['sku_snapshot']) . '</td>'
                . '<td>' . self::esc($l['item_name_snapshot']) . '</td>'
                . '<td>' . self::esc($l['category_name'] ?? '-') . '</td>'
                . '<td class="num">' . self::fmt((float) $l['qty']) . '</td>'
                . '<td>' . self::esc($l['unit_code']) . '</td>'
                . '<td class="num">' . self::money((float) $l['selling_unit_price']) . '</td>'
                . '<td class="num">' . self::money((float) $l['subtotal']) . '</td>'
                . '</tr>';
        }

        $title = "Invoice {$header['invoice_number']}";
        $body = self::docHeader('INVOICE', [
            ['No. Invoice', $header['invoice_number']],
            ['Tanggal Invoice', $header['invoice_date']],
            ['No. DO', $header['do_number']],
            ['Dari', "Gudang Besar / {$header['from_warehouse_code']} ({$header['from_warehouse_name']})"],
            ['Kepada', $header['bakery_name']],
            ['Alamat', $header['bakery_address'] ?? '-'],
        ]);
        $body .= '<table class="doc-table"><thead><tr>'
            . '<th>No</th><th>SKU</th><th>Nama Barang</th><th>Kategori</th><th>Qty</th><th>Satuan</th><th>Harga Jual</th><th>Subtotal</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';

        $summaryRows = [['Subtotal', self::money((float) $header['subtotal'])]];
        if ((float) $header['discount_amount'] > 0) { $summaryRows[] = ['Discount', '-' . self::money((float) $header['discount_amount'])]; }
        if ((float) $header['tax_amount'] > 0) { $summaryRows[] = ['PPN', self::money((float) $header['tax_amount'])]; }
        if ((float) $header['shipping_amount'] > 0) { $summaryRows[] = ['Ongkos Kirim', self::money((float) $header['shipping_amount'])]; }
        $summaryRows[] = ['GRAND TOTAL', self::money((float) $header['grand_total'])];
        $body .= '<table class="doc-summary">';
        foreach ($summaryRows as $i => [$label, $value]) {
            $bold = $i === count($summaryRows) - 1 ? ' class="total"' : '';
            $body .= "<tr{$bold}><td>{$label}</td><td class=\"num\">{$value}</td></tr>";
        }
        $body .= '</table>';

        $body .= self::signatureBlock([
            ['Dibuat Oleh', $header['created_by_name'] ?? '-'],
            ['Disetujui', $header['issued_by_name'] ?? '-'],
            ['Diterima Bakery', '-'],
        ]);

        return self::wrapDocument($title, $body);
    }

    /** @param list<array{0:string,1:?string}> $infoRows */
    private static function docHeader(string $title, array $infoRows): string
    {
        $rows = '';
        foreach ($infoRows as [$label, $value]) {
            $rows .= '<tr><td class="label">' . self::esc($label) . '</td><td>' . self::esc((string) ($value ?? '-')) . '</td></tr>';
        }
        return '<img src="/assets/images/amor-logo.jpg" alt="AMOR Group" class="doc-logo">'
            . '<h1 class="doc-title">' . self::esc($title) . '</h1>'
            . '<table class="doc-info">' . $rows . '</table>';
    }

    /** @param list<array{0:string,1:string}> $signers */
    private static function signatureBlock(array $signers): string
    {
        $cells = '';
        foreach ($signers as [$label, $name]) {
            $cells .= '<div class="sig-cell"><div class="sig-line"></div><div class="sig-label">' . self::esc($label) . '</div><div class="sig-name">' . self::esc($name) . '</div></div>';
        }
        return '<div class="sig-block">' . $cells . '</div>';
    }

    private static function wrapDocument(string $title, string $bodyHtml): string
    {
        $safeTitle = self::esc($title);
        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>{$safeTitle}</title>
<style>
    @page { size: A4 portrait; margin: 18mm 16mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #1a1a1a; background: #fff; margin: 0; font-size: 12px; }
    .doc-logo { display: block; max-width: 140px; max-height: 70px; width: auto; height: auto; object-fit: contain; margin-bottom: 8px; }
    .doc-title { font-size: 18px; margin: 0 0 14px; letter-spacing: 0.03em; }
    .doc-info { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .doc-info td { padding: 2px 0; vertical-align: top; }
    .doc-info td.label { width: 160px; color: #555; }
    .doc-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .doc-table th, .doc-table td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
    .doc-table th { background: #f2f2f2; font-weight: 700; }
    .doc-table td.num, .doc-table th.num { text-align: right; }
    .doc-summary { width: 260px; margin-left: auto; border-collapse: collapse; }
    .doc-summary td { padding: 3px 0; }
    .doc-summary td.num { text-align: right; }
    .doc-summary tr.total td { font-weight: 700; border-top: 1px solid #333; padding-top: 6px; }
    .doc-notes { margin: 10px 0 24px; }
    .sig-block { display: flex; justify-content: space-between; margin-top: 36px; }
    .sig-cell { width: 30%; text-align: center; }
    .sig-line { border-top: 1px solid #333; margin-bottom: 6px; height: 40px; }
    .sig-label { font-weight: 700; }
    .sig-name { color: #555; }
    @media print { body { -webkit-print-color-adjust: exact; } }
</style>
</head>
<body>
{$bodyHtml}
</body>
</html>
HTML;
    }

    private static function esc(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    private static function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }

    private static function money(float $value): string
    {
        return 'Rp ' . number_format($value, 0, ',', '.');
    }
}
