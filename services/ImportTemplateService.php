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
