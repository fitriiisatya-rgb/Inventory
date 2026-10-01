<?php
declare(strict_types=1);

namespace App\Services;

use ZipArchive;
use RuntimeException;

/**
 * PHASE V2.3 — minimal, dependency-free XLSX writer. This codebase has no
 * composer/vendor directory and no PhpSpreadsheet-style library anywhere
 * (confirmed by audit before writing this), so "Export Excel" is built
 * directly on PHP's built-in ZipArchive + hand-written OOXML — the
 * standard no-dependency technique, not a CSV-renamed-to-.xlsx shortcut.
 *
 * Deliberately minimal: every cell is either a plain number or an inline
 * string (`t="inlineStr"`) — no shared-strings table, no formulas.
 *
 * PHASE V2.16: every numeric data cell (not the header row) now carries a
 * fixed "#,##0.######" display format (style id 2) instead of Excel's
 * bare "General" — purely a VISUAL numFmt on top of the exact same raw
 * <v> value every existing caller already wrote, so nothing that reads
 * a cell's value back (XlsxReaderService, every existing export test)
 * is affected. Each sheet may also optionally set 'freeze_header' (bool),
 * 'autofilter' (bool), and 'col_widths' (list<float>, Excel character-
 * width units) — all three default to off/empty, so every one of this
 * file's pre-V2.16 callers renders byte-identical XML to before.
 */
final class ExcelWriterService
{
    /**
     * Section M (formula-injection protection): a string cell whose first
     * character is one Excel/LibreOffice would interpret as a formula
     * trigger (=, +, -, @, tab, CR) is prefixed with a single quote so it
     * is always rendered as literal text, never evaluated. This is NOT
     * applied automatically inside write()/rowXml() for every caller (a
     * legitimate value like a "-5%" note would otherwise silently gain a
     * leading quote for callers that never asked for this) — callers that
     * render user-supplied/uploaded source text (e.g.
     * StockOpnameFinalExportService) call this explicitly on every such
     * value before handing rows to write().
     */
    public static function sanitizeCellText(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * @param array<string, array{headers: list<string>, rows: list<list<int|float|string|null>>, freeze_header?: bool, autofilter?: bool, col_widths?: list<float>}> $sheets
     *        Keyed by sheet name (max 31 chars, Excel's own limit).
     */
    public static function write(string $path, array $sheets): void
    {
        if ($sheets === []) {
            throw new RuntimeException('ExcelWriterService::write() requires at least one sheet');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create XLSX file at {$path}");
        }

        $zip->addEmptyDir('_rels');
        $zip->addEmptyDir('xl');
        $zip->addEmptyDir('xl/_rels');
        $zip->addEmptyDir('xl/worksheets');

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml(count($sheets)));
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml(array_keys($sheets)));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml(count($sheets)));
        $zip->addFromString('xl/styles.xml', self::stylesXml());

        $i = 1;
        foreach ($sheets as $sheet) {
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", self::sheetXml(
                $sheet['headers'], $sheet['rows'],
                $sheet['freeze_header'] ?? false, $sheet['autofilter'] ?? false, $sheet['col_widths'] ?? []
            ));
            $i++;
        }

        $zip->close();
    }

    private static function contentTypesXml(int $sheetCount): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$i}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /** @param list<string> $sheetNames */
    private static function workbookXml(array $sheetNames): string
    {
        $sheetsXml = '';
        $i = 1;
        foreach ($sheetNames as $name) {
            $safe = htmlspecialchars(mb_substr($name, 0, 31), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $sheetsXml .= "<sheet name=\"{$safe}\" sheetId=\"{$i}\" r:id=\"rId{$i}\"/>";
            $i++;
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>{$sheetsXml}</sheets>"
            . '</workbook>';
    }

    private static function workbookRelsXml(int $sheetCount): string
    {
        $rels = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels .= "<Relationship Id=\"rId{$i}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$i}.xml\"/>";
        }
        $stylesRid = $sheetCount + 1;
        $rels .= "<Relationship Id=\"rId{$stylesRid}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>";
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private static function stylesXml(): string
    {
        // Three cell formats: 0 = default, 1 = bold (header row), 2 =
        // numeric data ("#,##0.######"). PHASE V2.16: <numFmts> must be
        // the FIRST child of <styleSheet> per the OOXML schema (ECMA-376
        // CT_Stylesheet) — fonts/fills/borders/cellStyleXfs/cellXfs/
        // cellStyles all come after it, never before.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.######"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" xfId="0" applyFont="1"/>'
            // style 2 — numeric data cells: "#,##0.######" (thousands
            // separator, up to 6 decimals, no forced trailing zeros).
            . '<xf numFmtId="164" fontId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * @param list<string> $headers
     * @param list<list<int|float|string|null>> $rows
     * @param list<float> $colWidths
     */
    private static function sheetXml(array $headers, array $rows, bool $freezeHeader = false, bool $autofilter = false, array $colWidths = []): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($freezeHeader) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if (!empty($colWidths)) {
            $xml .= '<cols>';
            foreach ($colWidths as $i => $w) {
                $colNum = $i + 1;
                $xml .= "<col min=\"{$colNum}\" max=\"{$colNum}\" width=\"" . self::numberString((float) $w) . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        $rowNum = 1;
        $xml .= self::rowXml($rowNum, $headers, true);
        $rowNum++;
        foreach ($rows as $row) {
            $xml .= self::rowXml($rowNum, $row, false);
            $rowNum++;
        }
        $xml .= '</sheetData>';

        if ($autofilter && !empty($headers)) {
            $lastCol = self::colLetter(count($headers) - 1);
            $lastRow = max($rowNum - 1, 1);
            $xml .= "<autoFilter ref=\"A1:{$lastCol}{$lastRow}\"/>";
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    /** @param list<int|float|string|null> $values */
    private static function rowXml(int $rowNum, array $values, bool $bold): string
    {
        $cells = '';
        $col = 0;
        $styleAttr = $bold ? ' s="1"' : ' s="2"';
        foreach ($values as $value) {
            $ref = self::colLetter($col) . $rowNum;
            if ($value === null) {
                $cells .= "<c r=\"{$ref}\"{$styleAttr}/>";
            } elseif (is_int($value) || is_float($value)) {
                $numStyle = $bold ? ' s="1"' : ' s="2"';
                $cells .= "<c r=\"{$ref}\"{$numStyle}><v>" . self::numberString($value) . '</v></c>';
            } else {
                $strStyle = $bold ? ' s="1"' : ' s="0"';
                $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= "<c r=\"{$ref}\" t=\"inlineStr\"{$strStyle}><is><t xml:space=\"preserve\">{$safe}</t></is></c>";
            }
            $col++;
        }
        return "<row r=\"{$rowNum}\">{$cells}</row>";
    }

    private static function numberString(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
    }

    private static function colLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $index = intdiv($index - 1, 26);
        }
        return $letter;
    }
}
