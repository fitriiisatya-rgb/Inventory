<?php
declare(strict_types=1);

namespace App\Services;

/**
 * PHASE V2.6A: "Download Template Excel" for the 4 Import page cards.
 *
 * Owner requirement: the template MUST be generated from the CURRENT
 * importer's actually-accepted header set, never a hand-maintained copy
 * that can silently drift out of sync. Each sheet() method below returns
 * EXACTLY the column list that constraint of ImportMasterItemService /
 * ImportSimpleMasterService actually reads (verified by reading those
 * services directly, not guessed) — if an importer's accepted columns
 * ever change, this file's HEADERS constants are the single place to
 * update, and both sides remain provably identical by inspection.
 *
 * Every template ships as two sheets: "Instructions" (plain-text guidance,
 * read by a human, never by the importer) and "Template" (the machine
 * header row, zero data rows). XlsxReaderService resolves the data sheet
 * by name — it prefers a sheet literally called "Template" or "Data" over
 * whichever sheet happens to be first — so re-uploading this exact file
 * unmodified round-trips cleanly regardless of sheet order, and no sample
 * row is included that could accidentally get imported as real data (a
 * deliberately empty Template sheet is safer than a sample row a user
 * forgets to delete).
 */
final class ImportTemplateService
{
    // Exactly ImportMasterItemService's accepted columns (services/ImportMasterItemService.php).
    private const MASTER_ITEM_HEADERS = [
        'sku', 'barcode', 'name', 'category', 'brand', 'base_unit',
        'purchase_unit', 'purchase_conversion', 'middle_unit', 'middle_conversion',
        'minimum_stock', 'status', 'default_supplier_code', 'notes',
    ];

    // Exactly ImportSimpleMasterService::COLUMNS['SUPPLIER'] + the optional
    // extra fields ImportSimpleMasterService::commit() reads for SUPPLIER
    // (contact_name, phone, notes — all optional, never required).
    private const SUPPLIER_HEADERS = ['supplier_code', 'supplier_name', 'status', 'contact_name', 'phone', 'notes'];

    // Exactly ImportSimpleMasterService::COLUMNS['DIVISION']. No extra
    // optional columns exist for DIVISION in ImportSimpleMasterService.
    private const DIVISION_HEADERS = ['division_code', 'division_name', 'status'];

    // Exactly ImportSimpleMasterService::COLUMNS['WAREHOUSE'] + the optional
    // warehouse_type column (validateRow()/commit() both read it, MAIN or
    // TRANSIT only — see ImportSimpleMasterService::WAREHOUSE_TYPES).
    private const WAREHOUSE_HEADERS = ['warehouse_code', 'warehouse_name', 'status', 'warehouse_type'];

    // PHASE V2.8 — exactly ImportLiveTransactionService's accepted columns
    // (services/ImportLiveTransactionService.php: validateRow()/postRow()).
    private const LIVE_TRANSACTION_HEADERS = [
        'transaction_date', 'transaction_type', 'warehouse_code', 'sku', 'input_qty', 'input_unit',
        'unit_price_input', 'supplier_code', 'division_code', 'bakery_destination_code', 'reference_no', 'notes',
        'line_discount_type', 'line_discount_value', 'invoice_discount_type', 'invoice_discount_value',
        'ppn_treatment', 'ppn_rate', 'ppn_creditable_pct', 'freight_treatment', 'freight_amount',
    ];

    // PHASE V2.9 — exactly ImportStockPolicyService's accepted columns
    // (services/ImportStockPolicyService.php: validateRow()/commit()).
    private const MINIMUM_STOCK_HEADERS = ['sku', 'warehouse_code', 'minimum_stock', 'buffer_stock'];

    /** @return array<string, array{headers: list<string>, rows: list<list<string>>}> */
    public static function build(string $importType): array
    {
        return match ($importType) {
            'MASTER_ITEM' => self::sheets(
                self::MASTER_ITEM_HEADERS,
                [
                    'sku: wajib, unik (ditolak jika sudah ada di master).',
                    'barcode: opsional.',
                    'name: wajib.',
                    'category, brand: opsional, teks bebas.',
                    'base_unit: wajib, harus salah satu satuan yang sudah dikenal sistem.',
                    'purchase_unit + purchase_conversion: opsional, tapi jika purchase_unit diisi maka purchase_conversion WAJIB > 0.',
                    'middle_unit + middle_conversion: opsional, tapi jika middle_unit diisi maka middle_conversion WAJIB > 0.',
                    'minimum_stock: opsional, angka >= 0.',
                    'status: ACTIVE atau INACTIVE (default ACTIVE jika dikosongkan).',
                    'default_supplier_code: opsional, harus cocok dengan kode supplier yang sudah ada (jika tidak ditemukan, baris tetap VALID/WARNING, kolom ini hanya dikosongkan).',
                    'notes: opsional.',
                    'Isi data pada sheet "Template". Baris pertama (header) JANGAN diubah.',
                ]
            ),
            'SUPPLIER' => self::sheets(
                self::SUPPLIER_HEADERS,
                [
                    'supplier_code: wajib, unik.',
                    'supplier_name: wajib.',
                    'status: ACTIVE atau INACTIVE (default ACTIVE jika dikosongkan).',
                    'contact_name, phone, notes: opsional.',
                    'Isi data pada sheet "Template". Baris pertama (header) JANGAN diubah.',
                ]
            ),
            'DIVISION' => self::sheets(
                self::DIVISION_HEADERS,
                [
                    'division_code: wajib, unik.',
                    'division_name: wajib.',
                    'status: ACTIVE atau INACTIVE (default ACTIVE jika dikosongkan).',
                    'Isi data pada sheet "Template". Baris pertama (header) JANGAN diubah.',
                ]
            ),
            'WAREHOUSE' => self::sheets(
                self::WAREHOUSE_HEADERS,
                [
                    'warehouse_code: wajib, unik.',
                    'warehouse_name: wajib.',
                    'status: ACTIVE atau INACTIVE (default ACTIVE jika dikosongkan).',
                    'warehouse_type: opsional, MAIN atau TRANSIT (default MAIN jika dikosongkan).',
                    'PENTING: gudang KARANG_TENGAH sedang PENDING_CUTOVER dan TIDAK BOLEH ditambahkan lewat template ini. Jangan menambahkan baris untuk Karang Tengah.',
                    'Isi data pada sheet "Template". Baris pertama (header) JANGAN diubah.',
                ]
            ),
            'LIVE_TRANSACTION' => self::sheets(
                self::LIVE_TRANSACTION_HEADERS,
                [
                    'transaction_date: wajib, format YYYY-MM-DD.',
                    'transaction_type: wajib, IN atau OUT saja (transfer/produksi/opening/adjustment TIDAK didukung importer ini).',
                    'warehouse_code: wajib, harus gudang yang AKTIF (gudang PENDING_CUTOVER seperti Karang Tengah akan DITOLAK).',
                    'sku: wajib, harus sudah ada di master barang.',
                    'input_qty: wajib, angka > 0.',
                    'input_unit: wajib, kode satuan yang sudah dikenal sistem dan punya konversi aktif untuk barang ini.',
                    'unit_price_input: WAJIB untuk baris IN (harga beli per satuan input, sebelum diskon/PPN/freight). Diabaikan untuk baris OUT.',
                    'supplier_code: opsional, hanya untuk IN (jika tidak ditemukan, baris tetap VALID/WARNING, hanya dikosongkan).',
                    'division_code: opsional, hanya untuk OUT (jika tidak ditemukan, baris tetap VALID/WARNING, hanya dikosongkan).',
                    'bakery_destination_code: opsional, hanya untuk OUT. Jika diisi, HARUS berupa kode bakery tujuan yang AKTIF — jika tidak ditemukan/tidak aktif, baris DITOLAK (ERROR, bukan sekadar dikosongkan). Kosongkan jika OUT ini tidak menuju bakery tertentu. Diabaikan untuk baris IN.',
                    'reference_no, notes: opsional.',
                    'line_discount_type/line_discount_value, invoice_discount_type/invoice_discount_value: opsional, hanya untuk IN (PERCENT/AMOUNT/NONE — default NONE/0, sama seperti Purchase Costing manual).',
                    'ppn_treatment: opsional, hanya untuk IN (CREDITABLE/NON_CREDITABLE/PARTIALLY_CREDITABLE/NONE — default NONE).',
                    'ppn_rate, ppn_creditable_pct: opsional, hanya untuk IN, angka.',
                    'freight_treatment: opsional, hanya untuk IN (CAPITALIZE/EXPENSE/NONE — default NONE).',
                    'freight_amount: opsional, hanya untuk IN, angka >= 0.',
                    'PENTING: setiap baris di sini membuat TRANSAKSI NYATA (efek FIFO nyata) persis seperti input manual Transaksi Masuk/Keluar — bukan data historis.',
                    'PENTING: baris diproses secara KRONOLOGIS berdasarkan transaction_date saat commit, bukan urutan baris di file.',
                    'Isi data pada sheet "Template". Baris pertama (header) JANGAN diubah.',
                ]
            ),
            'MINIMUM_STOCK' => self::sheets(
                self::MINIMUM_STOCK_HEADERS,
                [
                    'sku: wajib, harus sudah ada di master barang.',
                    'warehouse_code: wajib, harus gudang yang AKTIF (gudang PENDING_CUTOVER seperti Karang Tengah akan DITOLAK).',
                    'minimum_stock: wajib, angka >= 0. Ini akan menjadi Stok Minimal KHUSUS untuk kombinasi barang+gudang ini, menggantikan Stok Minimal global barang tersebut HANYA untuk gudang ini.',
                    'buffer_stock: opsional, angka >= 0. Kosongkan jika tidak ingin mengatur buffer.',
                    'PENTING: satu baris = satu kombinasi SKU + Gudang. Baris berikutnya dengan SKU+Gudang yang sama akan MENIMPA (update) nilai sebelumnya, bukan menduplikasi.',
                    'PENTING: ini TIDAK mengubah Stok Minimal global barang (Master Barang) — hanya menambah/mengubah pengaturan KHUSUS gudang tersebut.',
                    'Isi data pada sheet "Template". Baris pertama (header) JANGAN diubah.',
                ]
            ),
            default => throw new ValidationException(["unknown import template type: {$importType}"]),
        };
    }

    /** @param list<string> $headers @param list<string> $instructionLines */
    private static function sheets(array $headers, array $instructionLines): array
    {
        return [
            'Instructions' => [
                'headers' => ['Petunjuk Pengisian Template'],
                'rows' => array_map(static fn (string $line) => [$line], $instructionLines),
            ],
            'Template' => [
                'headers' => $headers,
                'rows' => [],
            ],
        ];
    }
}
