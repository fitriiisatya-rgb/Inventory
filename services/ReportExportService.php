<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Reports v3 — ONE export path for the five reports (Laporan Pergerakan Stok, IN / OUT, Pembelian, Nilai HPP, Stock Opname).
 *
 * Input is always the same plain structure every report service already produces for its Excel export:
 *     sheets = [ 'Sheet name' => ['headers' => list<string>, 'rows' => list<list<int|float|string|null>>, 'types'? => list<string>, 'freeze_header'? , 'autofilter'? ] ]
 * and the SAME structure feeds both outputs, so "Cetak" and "Download Excel" can never disagree with each other or with the screen filters:
 *   - xlsx  : a real OOXML workbook (ZipArchive, no dependency) with typed cells — numbers stay numbers (Rupiah / qty / count / percent formats), ISO dates and
 *             datetimes become real Excel dates, header row frozen + bold + filter, sensible column widths, TOTAL rows in bold. Nothing is turned into text.
 *   - json  : the same sheets + the inferred column types, which the print view (public/assets/js/report-tools.js) renders as a white, print-friendly document.
 * It is READ-ONLY and independent of ExcelWriterService (which other modules keep using unchanged).
 *
 * Column types: 'text' | 'int' | 'qty' | 'money' | 'pct' | 'date' | 'ts'. A report may pass them per sheet ('types'); otherwise they are inferred from the values
 * (ISO date / datetime strings, integers, floats) and the header wording (Rp / Nilai / HPP / Harga / Total / PPN / Diskon / Ongkir … = money; Qty / Kuantitas = qty; % = pct).
 * Unknown values written as "—" / "-" stay text cells, so a column never loses its numeric type because of one unknown.
 */
final class ReportExportService
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const MONEY_RE = '/\b(rp|nilai|harga|hpp|biaya|cost|subtotal|dpp|ppn|diskon|ongkir|ongkos|total|margin|selisih nilai|value|amount|jual|beli|persediaan|saldo|opening|closing|stok awal|stok akhir|pembelian|pemakaian|adjustment nilai)\b/i';
    private const NOT_MONEY_RE = '/(qty|kuantitas|jumlah|jml|%|persen|tarif|no\.|baris|sku|layer|hari|satuan|unit\b|item|transaksi|sesi|match|recount|status|tanggal|timestamp|waktu|kode|nama|catatan|referensi|gudang|supplier|bakery|petugas|oleh|ref\b)/i';

    // ======================================================================
    // type inference
    // ======================================================================

    /** @return list<string> */
    public static function inferTypes(array $headers, array $rows): array
    {
        // a "Keterangan / Nilai" summary sheet mixes Rupiah, counts and quantities in ONE column: keep them plain numbers (thousand separators), never a currency format that would mislabel a count
        if (($headers[0] ?? '') === 'Keterangan' && ($headers[1] ?? '') === 'Nilai' && count($headers) === 2) {
            return ['text', 'qty'];
        }
        $types = [];
        foreach ($headers as $c => $h) {
            $h = (string) $h;
            $seen = 0;
            $date = $ts = $num = $int = true;
            foreach ($rows as $r) {
                $v = $r[$c] ?? null;
                if ($v === null || $v === '' || $v === '—' || $v === '-') {
                    continue;
                }
                if ($c === 0 && is_string($v) && preg_match('/^(GRAND\s+)?TOTAL\b/i', $v)) {
                    continue;   // the label cell of a total row says nothing about the column's type
                }
                $seen++;
                if (is_int($v) || is_float($v)) {
                    $date = $ts = false;
                    if (!is_int($v) && floor($v) != $v) {
                        $int = false;
                    }
                    continue;
                }
                $num = $int = false;
                $s = (string) $v;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                    $date = false;
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $s)) {
                    $ts = false;
                }
            }
            if ($seen === 0) {
                $types[] = 'text';
            } elseif ($date) {
                $types[] = 'date';
            } elseif ($ts) {
                $types[] = 'ts';
            } elseif ($num) {
                if (preg_match('/%|persen/i', $h)) {
                    $types[] = 'pct';
                } elseif (preg_match('/qty|kuantitas/i', $h)) {
                    $types[] = 'qty';
                } elseif (preg_match(self::MONEY_RE, $h) && !preg_match(self::NOT_MONEY_RE, $h)) {
                    $types[] = 'money';
                } elseif ($int && preg_match('/jumlah|jml|\bno\.?$|baris|layer|item|transaksi|sesi|match|mismatch|recount|dikecualikan|belum/i', $h)) {
                    $types[] = 'int';
                } else {
                    $types[] = $int ? 'int' : 'qty';
                }
            } else {
                $types[] = 'text';
            }
        }
        return $types;
    }

    /** "2026-09-30" => 46295 (Excel 1900 date system serial). */
    public static function dateSerial(string $iso): float
    {
        $iso = str_replace('T', ' ', trim($iso));
        $dt = \DateTimeImmutable::createFromFormat(strlen($iso) > 10 ? (strlen($iso) > 16 ? '!Y-m-d H:i:s' : '!Y-m-d H:i') : '!Y-m-d', $iso, new \DateTimeZone('UTC'));
        if ($dt === false) {
            throw new RuntimeException("not an ISO date: {$iso}");
        }
        $base = new \DateTimeImmutable('1899-12-30 00:00:00', new \DateTimeZone('UTC'));
        return ($dt->getTimestamp() - $base->getTimestamp()) / 86400;
    }

    // ======================================================================
    // file names
    // ======================================================================

    /** "2026-10-01..2026-10-31" -> "2026-10" (a whole calendar month), a single day -> "2026-10-05", anything else "start_end". */
    public static function periodLabel(?string $start, ?string $end): string
    {
        $start = $start !== null ? substr($start, 0, 10) : '';
        $end = $end !== null ? substr($end, 0, 10) : '';
        if ($start === '' && $end === '') {
            return date('Y-m-d');
        }
        if ($start === '' || $end === '') {
            return $start !== '' ? $start : $end;
        }
        if ($start === $end) {
            return $start;
        }
        if (substr($start, 8, 2) === '01' && $end === date('Y-m-t', strtotime($start)) && substr($start, 0, 7) === substr($end, 0, 7)) {
            return substr($start, 0, 7);
        }
        return "{$start}_{$end}";
    }

    public static function fileName(string $report, string $period, string $ext = 'xlsx'): string
    {
        $slug = static fn (string $s): string => trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $s), '_');
        return $slug($report) . '_' . $slug($period) . '.' . $ext;
    }

    // ======================================================================
    // json (print view)
    // ======================================================================

    /**
     * @param array<string,array<string,mixed>> $sheets
     * @param list<array{0:string,1:string}> $meta label => value rows (filters, period, printed-at …)
     * @return array<string,mixed>
     */
    public static function toPayload(string $title, string $fileName, array $meta, array $sheets): array
    {
        $out = [];
        foreach ($sheets as $name => $s) {
            $types = $s['types'] ?? self::inferTypes($s['headers'], $s['rows']);
            $out[] = ['name' => (string) $name, 'headers' => array_values($s['headers']), 'types' => $types, 'rows' => $s['rows']];
        }
        return ['title' => $title, 'file_name' => $fileName, 'meta' => $meta, 'sheets' => $out, 'printed_at' => date('Y-m-d H:i:s')];
    }

    // ======================================================================
    // xlsx
    // ======================================================================

    /** style ids: see styles() — 0 text, 1 header, then [fmt x bold] pairs. */
    private const FMT = ['text' => 0, 'int' => 1, 'qty' => 2, 'money' => 3, 'pct' => 4, 'date' => 5, 'ts' => 6];

    /** @param array<string,array<string,mixed>> $sheets */
    public static function write(string $path, array $sheets): void
    {
        if ($sheets === []) {
            throw new RuntimeException('ReportExportService::write() requires at least one sheet');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create XLSX file at {$path}");
        }
        $names = [];
        foreach (array_keys($sheets) as $n) {
            $names[] = self::sheetName((string) $n, $names);
        }
        $n = count($sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= "<Override PartName=\"/xl/worksheets/sheet{$i}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($names as $i => $nm) {
            $id = $i + 1;
            $wb .= '<sheet name="' . htmlspecialchars($nm, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "\" sheetId=\"{$id}\" r:id=\"rId{$id}\"/>";
            $rels .= "<Relationship Id=\"rId{$id}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$id}.xml\"/>";
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', self::styles());
        $i = 1;
        foreach ($sheets as $s) {
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", self::sheetXml($s));
            $i++;
        }
        $zip->close();
    }

    private static function sheetName(string $name, array $taken): string
    {
        $clean = trim((string) preg_replace('/[\\\\\/\?\*\[\]:]+/', ' ', $name));
        $clean = mb_substr($clean === '' ? 'Sheet' : $clean, 0, 31);
        $base = $clean;
        $k = 2;
        while (in_array($clean, $taken, true)) {
            $suffix = ' ' . $k++;
            $clean = mb_substr($base, 0, 31 - strlen($suffix)) . $suffix;
        }
        return $clean;
    }

    private static function styles(): string
    {
        // cellXfs: 0 text · 1 header · then for each format f in [int, qty, money, pct, date, ts]: normal = 2 + 2*idx, bold = 3 + 2*idx · 14 = text bold
        $fmts = ['164' => '#,##0', '165' => '#,##0.######', '166' => '"Rp" #,##0.00##;[Red]-"Rp" #,##0.00##', '167' => '0.00"%"', '168' => 'yyyy-mm-dd', '169' => 'yyyy-mm-dd hh:mm:ss'];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="' . count($fmts) . '">';
        foreach ($fmts as $id => $code) {
            $xml .= "<numFmt numFmtId=\"{$id}\" formatCode=\"" . htmlspecialchars($code, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>';
        }
        $xml .= '</numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8EDF5"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color auto="1"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="15">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>';
        foreach (['164', '165', '166', '167', '168', '169'] as $f) {
            $xml .= "<xf numFmtId=\"{$f}\" fontId=\"0\" fillId=\"0\" borderId=\"0\" xfId=\"0\" applyNumberFormat=\"1\"/>";
            $xml .= "<xf numFmtId=\"{$f}\" fontId=\"1\" fillId=\"0\" borderId=\"0\" xfId=\"0\" applyNumberFormat=\"1\" applyFont=\"1\"/>";
        }
        $xml .= '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>';
        return $xml . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private static function styleId(string $type, bool $bold): int
    {
        if ($type === 'text') {
            return $bold ? 14 : 0;
        }
        $idx = array_search($type, ['int', 'qty', 'money', 'pct', 'date', 'ts'], true);
        return 2 + 2 * (int) $idx + ($bold ? 1 : 0);
    }

    /** @param array<string,mixed> $s */
    private static function sheetXml(array $s): string
    {
        $headers = array_values($s['headers']);
        $rows = $s['rows'];
        $types = $s['types'] ?? self::inferTypes($headers, $rows);
        $freeze = ($s['freeze_header'] ?? true) !== false;
        $filter = ($s['autofilter'] ?? true) !== false;
        $widths = [];
        foreach ($headers as $c => $h) {
            $w = mb_strlen((string) $h) + 2;
            foreach (array_slice($rows, 0, 300) as $r) {
                $v = $r[$c] ?? null;
                $len = is_float($v) ? 14 : mb_strlen((string) $v);
                $w = max($w, min($len, 60) + 2);
            }
            $widths[$c] = min(max($w, 9), 55);
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($freeze) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        $xml .= '<cols>';
        foreach ($widths as $i => $w) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData><row r="1" ht="30" customHeight="1">';
        foreach ($headers as $c => $h) {
            $xml .= '<c r="' . self::col($c) . '1" t="inlineStr" s="1"><is><t xml:space="preserve">' . self::esc((string) $h) . '</t></is></c>';
        }
        $xml .= '</row>';
        $rn = 2;
        foreach ($rows as $r) {
            $first = isset($r[0]) ? (string) $r[0] : '';
            $bold = (bool) preg_match('/^(GRAND\s+)?TOTAL\b/i', $first);
            $xml .= "<row r=\"{$rn}\">";
            foreach ($headers as $c => $_) {
                $v = $r[$c] ?? null;
                $ref = self::col($c) . $rn;
                $t = $types[$c] ?? 'text';
                if ($v === null || $v === '') {
                    $xml .= "<c r=\"{$ref}\" s=\"" . self::styleId('text', $bold) . '"/>';
                } elseif (is_int($v) || is_float($v)) {
                    $num = is_int($v) ? (string) $v : rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
                    $num = $num === '' || $num === '-0' ? '0' : $num;
                    $style = in_array($t, ['int', 'qty', 'money', 'pct'], true) ? $t : (is_int($v) ? 'int' : 'qty');
                    $xml .= "<c r=\"{$ref}\" s=\"" . self::styleId($style, $bold) . "\"><v>{$num}</v></c>";
                } elseif (($t === 'date' || $t === 'ts') && preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', (string) $v)) {
                    $xml .= "<c r=\"{$ref}\" s=\"" . self::styleId($t, $bold) . '"><v>' . rtrim(rtrim(sprintf('%.8F', self::dateSerial((string) $v)), '0'), '.') . '</v></c>';
                } else {
                    $xml .= "<c r=\"{$ref}\" t=\"inlineStr\" s=\"" . self::styleId('text', $bold) . '"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
            $rn++;
        }
        $xml .= '</sheetData>';
        if ($filter && $headers !== []) {
            $xml .= '<autoFilter ref="A1:' . self::col(count($headers) - 1) . max($rn - 1, 1) . '"/>';
        }
        return $xml . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToHeight="0"/></worksheet>';
    }

    private static function esc(string $s): string
    {
        // strip characters XML 1.0 forbids; keep tab / LF / CR
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function col(int $i): string
    {
        $l = '';
        $i++;
        while ($i > 0) {
            $rem = ($i - 1) % 26;
            $l = chr(65 + $rem) . $l;
            $i = intdiv($i - 1, 26);
        }
        return $l;
    }

    // ======================================================================
    // delivery (used by the GET routes)
    // ======================================================================

    /** Streams the workbook as a download with the given file name and returns (the caller exits). */
    public static function streamXlsx(array $sheets, string $fileName): void
    {
        $path = sys_get_temp_dir() . '/rv3_' . bin2hex(random_bytes(6)) . '.xlsx';
        self::write($path, $sheets);
        try {
            header('Content-Type: ' . self::MIME);
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
            header('Content-Length: ' . filesize($path));
            header('Access-Control-Expose-Headers: Content-Disposition');
            readfile($path);
        } finally {
            @unlink($path);
        }
    }
}
