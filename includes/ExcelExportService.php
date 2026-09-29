<?php
declare(strict_types=1);

/**
 * Hand-written .xlsx (OOXML) writer — no Composer dependency, so it works
 * unmodified on shared hosting with only the bundled php-zip extension.
 * Deliberately minimal: inline strings (no sharedStrings.xml table), two
 * cell styles (normal + bold header), no formulas, no merged cells. That
 * covers every report this app needs to export; anything fancier is out
 * of scope for the go-live MVP.
 */
final class ExcelExportService
{
    /** @var array<int,array{name:string,header:array<int,string>,rows:array<int,array<int,mixed>>}> */
    private array $sheets = [];

    /**
     * @param array<int,string> $header column titles, row 1, rendered bold
     * @param array<int,array<int,mixed>> $rows each a list of scalar cell
     *        values (string|int|float|null) in the same column order as $header
     */
    public function addSheet(string $name, array $header, array $rows): void
    {
        // Excel sheet name limits: <=31 chars, none of : \ / ? * [ ]
        $safeName = substr(preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $name), 0, 31);
        $this->sheets[] = ['name' => $safeName !== '' ? $safeName : 'Sheet', 'header' => $header, 'rows' => $rows];
    }

    /** @return string raw .xlsx file bytes */
    public function build(): string
    {
        if (empty($this->sheets)) {
            throw new RuntimeException('Tidak ada sheet untuk di-export.');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Gagal membuat file xlsx sementara.');
        }

        $zip->addEmptyDir('_rels');
        $zip->addEmptyDir('xl');
        $zip->addEmptyDir('xl/_rels');
        $zip->addEmptyDir('xl/worksheets');

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($sheet['header'], $sheet['rows']));
        }

        $zip->close();
        $bytes = file_get_contents($tmpPath);
        unlink($tmpPath);
        return $bytes;
    }

    private function contentTypesXml(): string
    {
        $overrides = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
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

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        $sheetsXml = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $sheetsXml .= '<sheet name="' . self::escape($sheet['name']) . "\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>{$sheetsXml}</sheets></workbook>";
    }

    private function workbookRelsXml(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $rels .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
        }
        $stylesRid = count($this->sheets) + 1;
        $rels .= "<Relationship Id=\"rId{$stylesRid}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>";
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    private function stylesXml(): string
    {
        // xf index 0 = default; xf index 1 = bold header.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** @param array<int,string> $header @param array<int,array<int,mixed>> $rows */
    private function sheetXml(array $header, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $xml .= '<row r="1">';
        foreach ($header as $col => $value) {
            $xml .= $this->cellXml(0, $col, (string) $value, true);
        }
        $xml .= '</row>';

        foreach ($rows as $rowIdx => $row) {
            $r = $rowIdx + 2; // header is row 1
            $xml .= "<row r=\"{$r}\">";
            foreach (array_values($row) as $col => $value) {
                $xml .= $this->cellXml($rowIdx + 1, $col, $value, false);
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function cellXml(int $rowIdx, int $col, mixed $value, bool $bold): string
    {
        $ref = self::columnLetter($col) . ($rowIdx + 1);
        $style = $bold ? ' s="1"' : '';
        if ($value === null || $value === '') {
            return "<c r=\"{$ref}\"{$style}/>";
        }
        if (is_int($value) || is_float($value)) {
            $v = is_float($value) ? self::formatFloat($value) : (string) $value;
            return "<c r=\"{$ref}\"{$style} t=\"n\"><v>{$v}</v></c>";
        }
        return "<c r=\"{$ref}\"{$style} t=\"inlineStr\"><is><t xml:space=\"preserve\">" . self::escape((string) $value) . '</t></is></c>';
    }

    private static function formatFloat(float $v): string
    {
        // Avoid scientific notation and trailing float noise (e.g. 0.1+0.2).
        $s = rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
        return $s === '' || $s === '-' ? '0' : $s;
    }

    private static function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - 1, 26);
        }
        return $letter;
    }

    private static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
