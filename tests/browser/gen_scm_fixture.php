<?php
declare(strict_types=1);

/**
 * PHASE V2.16.2 — generates a baseline SCM workbook matching the ACTUAL
 * real-world "Inventory September 2026 SCM (gudang besar).xlsx" shape
 * (parent header row 7, Stok Akhir sub-header row 8 under a true merge,
 * blank row 9, data from row 10), for Playwright's real-browser upload
 * test. Usage: php gen_scm_fixture.php <output_path> <sku> <qty>
 */

require_once __DIR__ . '/../../services/Exceptions.php';
require_once __DIR__ . '/../../services/ExcelWriterService.php';

use App\Services\ExcelWriterService;

[, $outPath, $sku, $qty] = $argv;

$blankRow = array_fill(0, 10, null);
$rows = [$blankRow, $blankRow, $blankRow, $blankRow, $blankRow]; // rows 2-6
$rows[] = ['No', 'Nama Barang', 'Kode Barang', 'Satuan', 'Isi', 'Harga', 'Stock Awal', 'Nominal Stok Awal', 'Stok Akhir', null]; // row 7
$rows[] = [null, null, null, null, null, null, null, null, 'QTY', 'Total stok']; // row 8
$rows[] = $blankRow; // row 9
$rows[] = [1, 'Item Playwright V2.16.2', $sku, 'KG', 1, 1000, 0, 0, (float) $qty, (float) $qty * 1000]; // row 10
// A footer row (stray "No", blank Kode/Nama/Satuan, #N/A qty) — must
// never be imported as a fake SKU.
$rows[] = [99, null, null, null, null, null, null, null, '#N/A', '#N/A'];

ExcelWriterService::write($outPath, ['SCM' => ['headers' => array_fill(0, 10, null), 'rows' => $rows]]);
