<?php
declare(strict_types=1);

/**
 * ReportExportService (reports v3: one export path for xlsx + print JSON) — pure PHP, no database.
 * Usage: php tests/report_export_test.php
 */

require_once __DIR__ . '/../services/ReportExportService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';

use App\Services\ReportExportService as X;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

$headers = ['Tanggal', 'Timestamp', 'No. Invoice', 'Jumlah Item', 'Qty', 'Subtotal Barang', 'PPN', 'Margin %', 'Total Item', 'Status', 'Selisih Nilai'];
$rows = [
    ['2026-09-05', '2026-09-05 10:15:30', 'INV-A', 3, 10.5, 28000.0, 2090.0, 12.34, 4, 'POSTED', -1200.5],
    ['2026-09-12', '2026-09-12 09:00', '—', 1, 100, 30000, 1500, '—', 1, 'POSTED', '—'],
    ['TOTAL', '', '', 4, '', 58000.0, 3590.0, '', 5, '', -1200.5],
];
$types = X::inferTypes($headers, $rows);
check('type inference: date / ts / text / int / qty / money / money / pct / int / text / money', $types === ['date', 'ts', 'text', 'int', 'qty', 'money', 'money', 'pct', 'int', 'text', 'money'], implode(',', $types));
check('a column with an unknown "—" keeps its numeric type', $types[7] === 'pct' && $types[10] === 'money');

$path = sys_get_temp_dir() . '/rv3_test_' . bin2hex(random_bytes(4)) . '.xlsx';
X::write($path, ['Ringkasan' => ['headers' => $headers, 'rows' => $rows], 'Sheet: with/bad*chars?' => ['headers' => ['A'], 'rows' => [['x'], ["bad\x01char & <tag>"]]], 'Ringkasan' . str_repeat('x', 40) => ['headers' => ['A'], 'rows' => []]]);
$z = new ZipArchive();
check('the workbook opens as a zip with 3 worksheets + styles', $z->open($path) === true && $z->locateName('xl/worksheets/sheet3.xml') !== false && $z->locateName('xl/styles.xml') !== false);
$wellFormed = true;
foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml'] as $part) {
    $d = new DOMDocument();
    if (!@$d->loadXML((string) $z->getFromName($part))) { $wellFormed = false; echo "  not well-formed: {$part}\n"; }
}
check('every XML part is well-formed (control characters stripped, & < > escaped)', $wellFormed);
$wb = (string) $z->getFromName('xl/workbook.xml');
check('sheet names are Excel-safe (no / \\ ? * : [ ], max 31 chars, unique)', !preg_match('#name="[^"]*[\\\\/?*\[\]:][^"]*"#', $wb) && preg_match_all('/<sheet name="([^"]{1,31})"/', $wb) === 3);
$s1 = (string) $z->getFromName('xl/worksheets/sheet1.xml');
$z->close();
check('dates are real Excel serials with a date style (2026-09-05 = 46270), datetimes carry a time fraction', str_contains($s1, '<v>46270</v>') && preg_match('#<c r="B2" s="\d+"><v>46270\.4274#', $s1) === 1, '');
check('numbers stay numbers (no inline string): qty 10.5, money 28000, negative -1200.5, int 3', str_contains($s1, '<v>10.5</v>') && str_contains($s1, '<v>28000</v>') && str_contains($s1, '<v>-1200.5</v>') && preg_match('#<c r="D2" s="\d+"><v>3</v>#', $s1) === 1);
check('unknown "—" stays a text cell and does not corrupt the numeric column', str_contains($s1, '<c r="C3" t="inlineStr"') && str_contains($s1, '<c r="H3" t="inlineStr"'));
check('the TOTAL row is bold; header row is frozen with an autofilter', str_contains($s1, 'state="frozen"') && str_contains($s1, '<autoFilter ref="A1:K4"/>') && preg_match('#<c r="A4" t="inlineStr" s="14">#', $s1) === 1);
$read = \App\Services\XlsxReaderService::read($path, 'Ringkasan');
check('the file is readable by the application\'s own XLSX reader (first sheet: header + 3 rows, values intact)', is_array($read) && count($read) >= 3, is_array($read) ? (string) count($read) : 'unreadable');
@unlink($path);

check('period labels: whole month → 2026-10; a day → the day; a range → start_end; empty → today', X::periodLabel('2026-10-01', '2026-10-31') === '2026-10' && X::periodLabel('2026-10-05', '2026-10-05') === '2026-10-05'
    && X::periodLabel('2026-10-01', '2026-10-15') === '2026-10-01_2026-10-15' && X::periodLabel(null, null) === date('Y-m-d') && X::periodLabel('2026-02-01', '2026-02-28') === '2026-02');
check('file names: Laporan_Pergerakan_Stok_2026-10-01_2026-10-31.xlsx · Laporan_Pembelian_2026-10.xlsx · unsafe characters replaced', X::fileName('Laporan_Pergerakan_Stok', '2026-10-01_2026-10-31') === 'Laporan_Pergerakan_Stok_2026-10-01_2026-10-31.xlsx'
    && X::fileName('Laporan Pembelian', X::periodLabel('2026-10-01', '2026-10-31')) === 'Laporan_Pembelian_2026-10.xlsx' && X::fileName('Laporan/Stock Opname', 'SO-20260930-0004') === 'Laporan_Stock_Opname_SO-20260930-0004.xlsx');
$p = X::toPayload('Laporan X', 'f.xlsx', [['Periode', 'a']], ['S' => ['headers' => $headers, 'rows' => $rows]]);
check('print payload: same sheets + inferred types + meta + printed_at', $p['sheets'][0]['types'] === $types && $p['sheets'][0]['rows'] === $rows && $p['meta'][0][0] === 'Periode' && strlen($p['printed_at']) === 19);
$pt = X::toPayload('T', 'f', [], ['S' => ['headers' => ['A'], 'rows' => [[1]], 'types' => ['pct']]]);
check('an explicit "types" list on a sheet overrides inference', $pt['sheets'][0]['types'] === ['pct']);

$pass = count(array_filter($results));
echo "\n{$pass} / " . count($results) . " PASSED\n";
exit($pass === count($results) ? 0 : 1);
