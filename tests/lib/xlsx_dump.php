<?php
declare(strict_types=1);

/**
 * Independent xlsx inspector for the browser / HTTP tests: prints one JSON document describing a workbook, read straight from the OOXML (not through the writer's own code).
 *   php tests/lib/xlsx_dump.php file.xlsx  ->  { sheets: [ { name, frozen, autofilter, col_widths, rows: [ [ {v, t, fmt} … ] … ] } ] }
 *   t: 'n' numeric, 's' string, 'd' numeric cell with a date number format, 'b' boolean;  fmt = the number-format code applied to the cell ('' = General)
 */
$file = $argv[1] ?? '';
$z = new ZipArchive();
if ($file === '' || $z->open($file) !== true) {
    fwrite(STDERR, "cannot open {$file}\n");
    exit(2);
}
$sx = static fn (string $name) => ($x = $z->getFromName($name)) === false ? null : simplexml_load_string($x);
$wb = $sx('xl/workbook.xml');
$ns = $wb->getNamespaces(true);
$rels = [];
foreach ($sx('xl/_rels/workbook.xml.rels')->Relationship as $r) {
    $rels[(string) $r['Id']] = (string) $r['Target'];
}
$strings = [];
if (($ss = $sx('xl/sharedStrings.xml')) !== null) {
    foreach ($ss->si as $si) {
        $t = '';
        foreach ($si->xpath('.//*[local-name()="t"]') as $n) {
            $t .= (string) $n;
        }
        $strings[] = $t;
    }
}
$styles = $sx('xl/styles.xml');
$fmts = [];
if (isset($styles->numFmts)) {
    foreach ($styles->numFmts->numFmt as $f) {
        $fmts[(int) $f['numFmtId']] = (string) $f['formatCode'];
    }
}
$builtin = [0 => '', 1 => '0', 2 => '0.00', 3 => '#,##0', 4 => '#,##0.00', 9 => '0%', 10 => '0.00%', 14 => 'm/d/yyyy', 15 => 'd-mmm-yy', 22 => 'm/d/yy h:mm'];
$xfs = [];
foreach ($styles->cellXfs->xf as $xf) {
    $id = (int) $xf['numFmtId'];
    $xfs[] = $fmts[$id] ?? ($builtin[$id] ?? "builtin:{$id}");
}
$isDate = static fn (string $f): bool => (bool) preg_match('/(?<![\\\\"])[dmyh]/i', preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/', '', $f) ?? '') && !preg_match('/^#|^0/', $f) || in_array($f, ['m/d/yyyy', 'd-mmm-yy', 'm/d/yy h:mm'], true);
$colIdx = static function (string $ref): int {
    preg_match('/^([A-Z]+)/', $ref, $m);
    $n = 0;
    foreach (str_split($m[1]) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
};
$out = ['sheets' => []];
foreach ($wb->sheets->sheet as $sh) {
    $rid = (string) $sh->attributes($ns['r'])['id'];
    $sheet = $sx('xl/' . ltrim($rels[$rid], '/'));
    $pane = isset($sheet->sheetViews->sheetView->pane) ? $sheet->sheetViews->sheetView->pane : null;
    $widths = [];
    if (isset($sheet->cols)) {
        foreach ($sheet->cols->col as $c) {
            $widths[] = (float) $c['width'];
        }
    }
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $i = $colIdx((string) $c['r']);
            $type = (string) $c['t'];
            $fmt = $xfs[(int) $c['s']] ?? '';
            if ($type === 's') {
                $cell = ['v' => $strings[(int) $c->v] ?? '', 't' => 's'];
            } elseif ($type === 'inlineStr') {
                $cell = ['v' => (string) ($c->is->t ?? ''), 't' => 's'];
            } elseif ($type === 'str') {
                $cell = ['v' => (string) $c->v, 't' => 's'];
            } elseif ($type === 'b') {
                $cell = ['v' => (string) $c->v === '1', 't' => 'b'];
            } else {
                $v = (string) $c->v;
                $cell = $v === '' ? ['v' => null, 't' => 'e'] : ['v' => (float) $v, 't' => $isDate($fmt) ? 'd' : 'n'];
            }
            $cell['fmt'] = $fmt;
            $cells[$i] = $cell;
        }
        $row2 = [];
        for ($k = 0; $k <= (empty($cells) ? -1 : max(array_keys($cells))); $k++) {
            $row2[] = $cells[$k] ?? ['v' => null, 't' => 'e', 'fmt' => ''];
        }
        $rows[] = $row2;
    }
    $out['sheets'][] = ['name' => (string) $sh['name'], 'frozen' => $pane !== null && (string) $pane['state'] === 'frozen', 'autofilter' => isset($sheet->autoFilter) ? (string) $sheet->autoFilter['ref'] : '', 'col_widths' => $widths, 'rows' => $rows];
}
echo json_encode($out);
