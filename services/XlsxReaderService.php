<?php
declare(strict_types=1);

namespace App\Services;

/**
 * PHASE V2.6A: shared dependency-free .xlsx reader, extracted verbatim from
 * ImportOpeningStockService::readXlsx() (the only importer that already
 * supported .xlsx) so ImportMasterItemService and ImportSimpleMasterService
 * can gain real .xlsx support too, rather than each hand-rolling a second,
 * possibly-diverging copy of the same ZipArchive/simplexml parsing.
 *
 * An .xlsx is just a zip of XML parts — this reads the shared strings table
 * and the correct worksheet's cell grid (never blindly sheet1.xml: an
 * "Instructions" sheet is commonly placed first, so the target worksheet is
 * resolved via workbook.xml + workbook.xml.rels, preferring a sheet
 * literally named "Template" or "Data", else the LAST sheet). No Composer
 * package is used anywhere in this codebase — this stays intentionally
 * minimal: text/number cell values only, exactly what these importers need.
 *
 * @return list<array<string,string>> rows keyed by the first (header) row's
 *   cell values, in column order; fully-blank trailing rows are skipped.
 */
final class XlsxReaderService
{
    public static function read(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ValidationException(["cannot open xlsx file: {$path}"]);
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sst = simplexml_load_string($sharedXml);
            foreach ($sst->si as $si) {
                $sharedStrings[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', (array) ($si->r ?? [])));
            }
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $sheetXml = false;
        if ($workbookXml !== false && $relsXml !== false) {
            $wb = simplexml_load_string($workbookXml);
            $rels = simplexml_load_string($relsXml);
            $targetById = [];
            foreach ($rels->Relationship as $rel) {
                $targetById[(string) $rel['Id']] = ltrim((string) $rel['Target'], '/');
            }
            $ns = $wb->getNamespaces(true);
            $rNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $chosenTarget = null;
            $lastTarget = null;
            foreach ($wb->sheets->sheet as $sheetEl) {
                $attrs = $sheetEl->attributes($rNs);
                $rid = (string) $attrs['id'];
                $target = $targetById[$rid] ?? null;
                if ($target === null) {
                    continue;
                }
                $target = str_starts_with($target, 'xl/') ? $target : ('xl/' . $target);
                $lastTarget = $target;
                if (in_array(strtolower((string) $sheetEl['name']), ['template', 'data'], true)) {
                    $chosenTarget = $target;
                }
            }
            $target = $chosenTarget ?? $lastTarget;
            if ($target !== null) {
                $sheetXml = $zip->getFromName($target);
            }
        }
        if ($sheetXml === false) {
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        }
        $zip->close();
        if ($sheetXml === false) {
            throw new ValidationException(['xlsx file has no readable worksheet']);
        }

        $sheet = simplexml_load_string($sheetXml);
        $grid = [];
        foreach ($sheet->sheetData->row as $rowXml) {
            $rowIndex = (int) $rowXml['r'];
            foreach ($rowXml->c as $cellXml) {
                $ref = (string) $cellXml['r'];
                preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
                $col = $m[1] ?? null;
                if ($col === null) {
                    continue;
                }
                $type = (string) $cellXml['t'];
                if ($type === 'inlineStr') {
                    $value = isset($cellXml->is->t) ? (string) $cellXml->is->t : '';
                } else {
                    $raw = isset($cellXml->v) ? (string) $cellXml->v : '';
                    $value = $type === 's' && $raw !== '' ? ($sharedStrings[(int) $raw] ?? '') : $raw;
                }
                $grid[$rowIndex][$col] = $value;
            }
        }

        if (empty($grid)) {
            return [];
        }
        ksort($grid);
        $rowNumbers = array_keys($grid);
        $headerRowNum = array_shift($rowNumbers);
        $headerRow = $grid[$headerRowNum];
        ksort($headerRow);
        $headers = array_values($headerRow);

        $rows = [];
        foreach ($rowNumbers as $rowNum) {
            $cells = $grid[$rowNum];
            $row = [];
            foreach ($headers as $i => $headerName) {
                $colLetter = self::colLetterAt($i);
                $row[$headerName] = $cells[$colLetter] ?? '';
            }
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function colLetterAt(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26);
        }
        return $letter;
    }
}
