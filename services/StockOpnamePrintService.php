<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.12C — clean, minimal A4-print discrepancy report for a Stock
 * Opname session. Server-rendered, print-friendly HTML/CSS, same
 * convention as DistributionPrintService (no PDF engine — the browser's
 * own Print dialog produces the PDF).
 *
 * LOGO: the same official AMOR logo used by DistributionPrintService
 * (public/assets/images/amor-logo.jpg), used exactly as supplied — never
 * redrawn/regenerated/resized out of aspect ratio.
 *
 * Section 22: "Full 1000-item print is optional; printable discrepancy
 * report is more useful" — this prints the session header/summary plus
 * only the DISCREPANCY rows (a nonzero difference, or an excluded item),
 * never every counted line.
 */
final class StockOpnamePrintService
{
    public static function renderResult(PDO $pdo, int $sessionId): string
    {
        $header = $pdo->prepare(
            'SELECT sos.*, w.code AS warehouse_code, w.name AS warehouse_name,
                    p1.username AS p1_username, p2.username AS p2_username, sv.username AS supervisor_username
             FROM stock_opname_sessions sos
             JOIN warehouses w ON w.id = sos.warehouse_id
             LEFT JOIN users p1 ON p1.id = sos.p1_user_id
             LEFT JOIN users p2 ON p2.id = sos.p2_user_id
             LEFT JOIN users sv ON sv.id = sos.supervisor_id
             WHERE sos.id = :id'
        );
        $header->execute(['id' => $sessionId]);
        $header = $header->fetch();
        if ($header === false) {
            throw new NotFoundException("stock opname session {$sessionId}");
        }

        $review = StockOpnameService::review($pdo, $sessionId);
        $summary = $review['summary'];
        $discrepancies = array_values(array_filter($review['lines'], static function (array $l): bool {
            return $l['is_excluded'] || ($l['difference_qty_base'] !== null && abs((float) $l['difference_qty_base']) > 0.0000005);
        }));

        $title = "Stock Opname " . ($header['session_number'] ?? "OPN-{$sessionId}");
        $body = self::docHeader('STOCK OPNAME', [
            ['No. Sesi', $header['session_number'] ?? "OPN-{$sessionId}"],
            ['Gudang', "{$header['warehouse_code']} ({$header['warehouse_name']})"],
            ['Tanggal Hitung', $header['session_date']],
            ['Status', $header['status']],
        ]);

        $body .= '<table class="doc-summary-grid"><tr>'
            . '<td>Total Item</td><td class="num">' . (int) $summary['total_items'] . '</td>'
            . '<td>Match</td><td class="num">' . (int) $summary['match'] . '</td>'
            . '<td>Mismatch</td><td class="num">' . (int) $summary['mismatch'] . '</td>'
            . '</tr><tr>'
            . '<td>Recounted</td><td class="num">' . (int) $summary['recounted'] . '</td>'
            . '<td>Belum Dihitung</td><td class="num">' . (int) $summary['not_counted'] . '</td>'
            . '<td>Dikecualikan</td><td class="num">' . (int) $summary['excluded'] . '</td>'
            . '</tr>' . ((int) ($summary['legacy_counted'] ?? 0) > 0
                ? '<tr><td>Hitung Lama (Single Count)</td><td class="num">' . (int) $summary['legacy_counted'] . '</td><td></td><td></td><td></td><td></td></tr>'
                : '') . '</table>';

        $rows = '';
        foreach ($discrepancies as $l) {
            $rows .= '<tr>'
                . '<td>' . self::esc($l['sku']) . '</td>'
                . '<td>' . self::esc($l['name']) . '</td>'
                . '<td class="num">' . self::fmt((float) $l['system_qty_base']) . '</td>'
                . '<td class="num">' . ($l['p1_qty_base'] !== null ? self::fmt((float) $l['p1_qty_base']) : '-') . '</td>'
                . '<td class="num">' . ($l['p2_qty_base'] !== null ? self::fmt((float) $l['p2_qty_base']) : '-') . '</td>'
                . '<td class="num">' . ($l['recount_qty_base'] !== null ? self::fmt((float) $l['recount_qty_base']) : '-') . '</td>'
                . '<td class="num">' . ($l['final_physical_qty_base'] !== null ? self::fmt((float) $l['final_physical_qty_base']) : '-') . '</td>'
                . '<td class="num">' . ($l['difference_qty_base'] !== null ? self::fmt((float) $l['difference_qty_base']) : '-') . '</td>'
                . '<td>' . self::esc((string) ($l['notes'] ?? ($l['recount_reason'] ?? '-'))) . '</td>'
                . '</tr>';
        }
        $body .= '<div class="doc-notes"><b>Selisih (Discrepancy):</b></div>';
        $body .= '<table class="doc-table"><thead><tr>'
            . '<th>SKU</th><th>Nama Barang</th><th>Sistem</th><th>P1</th><th>P2</th><th>Recount</th><th>Final</th><th>Selisih</th><th>Catatan</th>'
            . '</tr></thead><tbody>' . ($rows !== '' ? $rows : '<tr><td colspan="9">Tidak ada selisih — stok sesuai.</td></tr>') . '</tbody></table>';

        $body .= self::signatureBlock([
            ['Petugas 1 (P1)', $header['p1_username'] ?? '-'],
            ['Petugas 2 (P2)', $header['p2_username'] ?? '-'],
            ['Supervisor', $header['supervisor_username'] ?? '-'],
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
    .doc-info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .doc-info td { padding: 2px 0; vertical-align: top; }
    .doc-info td.label { width: 160px; color: #555; }
    .doc-summary-grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; border: 1px solid #ccc; }
    .doc-summary-grid td { border: 1px solid #ccc; padding: 5px 8px; }
    .doc-summary-grid td.num { text-align: right; font-weight: 700; width: 60px; }
    .doc-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .doc-table th, .doc-table td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
    .doc-table th { background: #f2f2f2; font-weight: 700; }
    .doc-table td.num, .doc-table th.num { text-align: right; }
    .doc-notes { margin: 10px 0 6px; }
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
}
