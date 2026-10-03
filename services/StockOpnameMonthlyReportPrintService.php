<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.16.6 — report-specific Print/PDF for "Laporan Stock Opname"
 * (the finance/accounting/audit monthly report), entirely SEPARATE from
 * StockOpnamePrintService (the existing, unmodified operational A4
 * PORTRAIT discrepancy-only print used by "Proses Stock Opname" — that
 * file is untouched by this feature and keeps serving its own route,
 * GET /stock-opname/{id}/print).
 *
 * This exists because StockOpnamePrintService cannot be reused for this
 * report: it is A4 portrait, prints only discrepancy rows, has no finance
 * summary, and has no Rupiah system/physical/variance values — none of
 * which fits a finance-oriented monthly report. Rather than modify that
 * operational print (used by STOCK_OPNAME_MANAGE workflows and explicitly
 * off-limits here), this class renders its own A4 LANDSCAPE document.
 *
 * Data comes from StockOpnameMonthlyReportService::detailForPrint() —
 * the SAME formatLine()/financeSummary()/categorySummary()/
 * findingsV1Summaries() business logic the on-screen report uses, called
 * exactly once per print request (no second reconciliation computation,
 * no re-derived formula) — so the printed figures and the on-screen
 * figures can never disagree. Every row is printed, not only the current
 * UI page and not only discrepancies.
 *
 * HPP / Unit Cost is NEVER printed — only the Rupiah VALUE columns already
 * computed (HPP-free) by StockOpnameMonthlyReportService.
 */
final class StockOpnameMonthlyReportPrintService
{
    public static function renderResult(PDO $pdo, int $sessionId, ?string $printedBy = null): string
    {
        $detail = StockOpnameMonthlyReportService::detailForPrint($pdo, $sessionId);
        $session = $detail['session'];
        $finance = $detail['finance_summary'];
        $items = $detail['items'];

        $title = 'Laporan Stock Opname ' . ($session['session_number'] ?? ('OPN-' . $sessionId));

        $body = self::docHeader([
            ['No. SO', $session['session_number'] ?? ('OPN-' . $sessionId)],
            ['Gudang', trim(($session['warehouse_code'] ?? '') . ' (' . ($session['warehouse_name'] ?? '') . ')', ' ()')],
            ['Tanggal SO', $session['session_date'] ?? null],
            ['Status', $session['status'] ?? null],
            ['Finalized', self::fmtDateTime($session['finalized_at'] ?? null) . ($session['finalized_by'] ? ' — ' . $session['finalized_by'] : '')],
            ['Posted', self::fmtDateTime($session['posted_at'] ?? null) . ($session['posted_by'] ? ' — ' . $session['posted_by'] : '')],
            ['Counting Model', $session['counting_model'] ?? null],
        ]);

        $body .= self::financeSummaryBlock($finance);
        $body .= self::itemsTable($items);
        $body .= self::footer($printedBy);

        return self::wrapDocument($title, $body);
    }

    /** @param list<array{0:string,1:?string}> $infoRows */
    private static function docHeader(array $infoRows): string
    {
        $rows = '';
        foreach ($infoRows as [$label, $value]) {
            $rows .= '<tr><td class="label">' . self::esc($label) . '</td><td>' . self::esc((string) ($value ?? '-')) . '</td></tr>';
        }
        return '<div class="doc-brand">'
            . '<div class="doc-brand-name">AMORCAKES AND BAKERY</div>'
            . '<div class="doc-brand-sub">PT. Inovasi Sukses Persada</div>'
            . '</div>'
            . '<h1 class="doc-title">LAPORAN STOCK OPNAME</h1>'
            . '<table class="doc-info">' . $rows . '</table>';
    }

    /** @param array<string,mixed> $f */
    private static function financeSummaryBlock(array $f): string
    {
        $cell = static fn (string $label, string $value): string => '<td>' . self::esc($label) . '</td><td class="num">' . self::esc($value) . '</td>';

        return '<table class="doc-summary-grid"><tr>'
            . $cell('Total Item', (string) $f['total_item_scope'])
            . $cell('Sesuai', (string) $f['sesuai'])
            . $cell('Selisih (+)', (string) $f['selisih_plus'])
            . $cell('Selisih (-)', (string) $f['selisih_minus'])
            . '</tr><tr>'
            . $cell('Rusak', (string) $f['rusak'])
            . $cell('Expired', (string) $f['expired'])
            . $cell('Deadstock', (string) $f['deadstock'])
            . '<td></td><td></td>'
            . '</tr><tr>'
            . $cell('Nilai Stok Sistem', self::fmtMoney((float) $f['nilai_stok_sistem']))
            . $cell('Nilai Stok Fisik Final', self::fmtMoney((float) $f['nilai_stok_fisik_final']))
            . $cell('Selisih Nilai', self::fmtMoney((float) $f['selisih_nilai']))
            . '<td></td><td></td>'
            . '</tr><tr>'
            . $cell('Qty Sistem', self::fmtQty((float) $f['qty_sistem']))
            . $cell('Qty Fisik Final', self::fmtQty((float) $f['qty_fisik_final']))
            . $cell('Selisih Qty', self::fmtQty((float) $f['selisih_qty']))
            . '<td></td><td></td>'
            . '</tr></table>';
    }

    /** @param list<array<string,mixed>> $items */
    private static function itemsTable(array $items): string
    {
        $rows = '';
        $no = 0;
        foreach ($items as $l) {
            $no++;
            $rows .= '<tr>'
                . '<td class="num">' . $no . '</td>'
                . '<td>' . self::esc((string) $l['sku']) . '</td>'
                . '<td>' . self::esc((string) $l['name']) . '</td>'
                . '<td>' . self::esc((string) $l['category']) . '</td>'
                . '<td>' . self::esc((string) $l['unit']) . '</td>'
                . '<td class="num">' . self::fmtQtyOrDash($l['system_qty']) . '</td>'
                . '<td class="num">' . self::fmtMoneyOrDash($l['system_value']) . '</td>'
                . '<td class="num">' . self::fmtQtyOrDash($l['physical_qty']) . '</td>'
                . '<td class="num">' . self::fmtMoneyOrDash($l['physical_value']) . '</td>'
                . '<td class="num">' . self::fmtQtyOrDash($l['variance_qty']) . '</td>'
                . '<td class="num">' . self::fmtMoneyOrDash($l['variance_value']) . '</td>'
                . '<td class="num">' . self::fmtQty((float) $l['rusak_qty']) . '</td>'
                . '<td class="num">' . self::fmtQty((float) $l['expired_qty']) . '</td>'
                . '<td class="num">' . self::fmtQty((float) $l['deadstock_qty']) . '</td>'
                . '<td>' . self::esc((string) ($l['keterangan'] ?? '-')) . '</td>'
                . '</tr>';
        }

        return '<table class="doc-table"><thead><tr>'
            . '<th>No</th><th>Kode Barang</th><th>Nama Barang</th><th>Kategori</th><th>Satuan</th>'
            . '<th>Stok Sistem Qty</th><th>Stok Sistem Nilai</th>'
            . '<th>Stok Fisik Final Qty</th><th>Stok Fisik Final Nilai</th>'
            . '<th>Selisih Qty</th><th>Selisih Nilai</th>'
            . '<th>Rusak</th><th>Expired</th><th>Deadstock</th><th>Keterangan</th>'
            . '</tr></thead><tbody>' . ($rows !== '' ? $rows : '<tr><td colspan="15">Tidak ada item.</td></tr>') . '</tbody></table>';
    }

    private static function footer(?string $printedBy): string
    {
        $line = 'Dicetak: ' . self::fmtDateTime(date('Y-m-d H:i:s'));
        if ($printedBy !== null && $printedBy !== '') {
            $line .= ' — ' . $printedBy;
        }
        return '<div class="doc-footer">' . self::esc($line) . '</div>';
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
    @page { size: A4 landscape; margin: 14mm 12mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #1a1a1a; background: #fff; margin: 0; font-size: 11px; }
    .doc-brand-name { font-size: 16px; font-weight: 700; letter-spacing: 0.02em; }
    .doc-brand-sub { font-size: 12px; color: #444; margin-bottom: 8px; }
    .doc-title { font-size: 16px; margin: 4px 0 12px; letter-spacing: 0.03em; }
    .doc-info { width: 60%; border-collapse: collapse; margin-bottom: 12px; }
    .doc-info td { padding: 2px 0; vertical-align: top; }
    .doc-info td.label { width: 140px; color: #555; }
    .doc-summary-grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; border: 1px solid #ccc; }
    .doc-summary-grid td { border: 1px solid #ccc; padding: 4px 8px; }
    .doc-summary-grid td.num { text-align: right; font-weight: 700; }
    .doc-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .doc-table th, .doc-table td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
    .doc-table th { background: #f2f2f2; font-weight: 700; }
    .doc-table thead { display: table-header-group; }
    .doc-table tr { page-break-inside: avoid; }
    .doc-table td.num, .doc-table th.num { text-align: right; }
    .doc-footer { margin-top: 14px; color: #555; }
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

    private static function fmtQty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }

    private static function fmtQtyOrDash(?float $value): string
    {
        return $value !== null ? self::fmtQty($value) : '-';
    }

    private static function fmtMoney(float $value): string
    {
        return 'Rp ' . number_format($value, 0, ',', '.');
    }

    private static function fmtMoneyOrDash(?float $value): string
    {
        return $value !== null ? self::fmtMoney($value) : '-';
    }

    private static function fmtDateTime(?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        $ts = strtotime($value);
        return $ts !== false ? date('d-m-Y H:i', $ts) : $value;
    }
}
