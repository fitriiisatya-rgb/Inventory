<?php
declare(strict_types=1);

/**
 * PHASE G-DATA 1B — unit conversion reconstruction runner.
 *
 * IMPORTANT — READ THIS BEFORE RUNNING AGAINST REAL DATA:
 * The real input files this phase specifies (template_master_data_barang_
 * gudang_besar.xlsx, _cibadak.xlsx, _karangtengah.xlsx, stok_awal_september_
 * gudang_besar.xlsx / _cibadak.xlsx / _karangtengah.xlsx,
 * phase_g_real_data_review_v4.xlsx) and the legacy `dbinventory` database
 * were NOT available in the environment this script was written in — they
 * were never uploaded/attached, and no legacy database connection exists.
 *
 * The $SAMPLE_INPUT below therefore encodes ONLY the specific SKUs/prices/
 * identity conflicts given directly, inline, in the Phase G-DATA 1B
 * instructions themselves — it is a proof-of-concept fixture proving the
 * engine's rules produce the exact classifications described (price-ratio
 * ≈15 candidates, the ≈1000x unit-label-mismatch, the two BLOCKED identity
 * conflicts, the 11 Global Master candidates, the 2 duplicate-source SKUs).
 * It is NOT a real analysis of the business's actual SKU catalog.
 *
 * Once the real files are provided, replace loadSampleInput() below with a
 * real loader (reuse the xlsx-reading approach from
 * scripts/generate_import_quality_report.php / the importer services) that
 * builds the same $input shape per SKU from the real workbook rows plus a
 * real `dbinventory` extraction, and re-run this script.
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/UnitConversionReconstructionService.php';

use App\Services\Database;
use App\Services\UnitConversionReconstructionService as Engine;

function loadSampleInput(): array
{
    return [
        [
            'sku' => '999208',
            'item_name_by_source' => ['KARANG_TENGAH' => 'PREMIX MENTEGA BASIC', 'MASTER' => 'PREMIX MENTEGA BASIC'],
            'price_records' => [
                ['source' => 'KARANG_TENGAH', 'unit' => 'PCS', 'price_per_unit' => 580930],
                ['source' => 'MASTER', 'unit' => 'KG', 'price_per_unit' => 38728.67],
            ],
        ],
        [
            'sku' => '999209',
            'item_name_by_source' => ['KARANG_TENGAH' => 'PREMIX MENTEGA ROTI', 'MASTER' => 'PREMIX MENTEGA ROTI'],
            'price_records' => [
                ['source' => 'KARANG_TENGAH', 'unit' => 'PCS', 'price_per_unit' => 537848],
                ['source' => 'MASTER', 'unit' => 'KG', 'price_per_unit' => 35856.53],
            ],
        ],
        [
            'sku' => '140539',
            'item_name_by_source' => ['MASTER' => 'MUTIARA PUTIH 8 MM', 'GUDANG_BESAR' => 'MUTIARA PUTIH 8 MM'],
            'price_records' => [
                ['source' => 'MASTER', 'unit' => 'KG', 'price_per_unit' => 305000],
                ['source' => 'OPENING_GUDANG_BESAR', 'unit' => 'KG', 'price_per_unit' => 305],
            ],
        ],
        [
            'sku' => '140541',
            'item_name_by_source' => ['STOK_KARANG_TENGAH' => 'MUTIARA PUTIH 10 MM', 'MASTER' => 'CREAMFILL CLASSIC BANANA'],
        ],
        [
            'sku' => '140509',
            'item_name_by_source' => ['STOK_KARANG_TENGAH' => 'MUTIARA WARNA', 'MASTER' => 'MUTE SILVER'],
        ],
        // 11 Global Master candidates — stock-only at Karang Tengah, no existing master row.
        ...array_map(fn ($sku) => [
            'sku' => $sku,
            'item_name_by_source' => ['STOK_KARANG_TENGAH' => "(nama dari data stok Karang Tengah — SKU {$sku})"],
            'is_global_master_candidate' => true,
        ], ['33515', '140542', '140543', '140544', '140545', '140546', '140547', '140548', '140549', '140550', '140551']),
        // Karang Tengah duplicate-source SKUs.
        [
            'sku' => '900240',
            'item_name_by_source' => ['STOK_KARANG_TENGAH' => '(item duplikat — lihat catatan)'],
            'is_duplicate_source' => true,
            'duplicate_note' => 'Karang Tengah stock data appears to duplicate this SKU under another code — retain ONE global SKU identity; do not create a second global item. Source discrepancy retained in this report for audit.',
        ],
        [
            'sku' => '900251',
            'item_name_by_source' => ['STOK_KARANG_TENGAH' => '(item duplikat — lihat catatan)'],
            'is_duplicate_source' => true,
            'duplicate_note' => 'Karang Tengah stock data appears to duplicate this SKU under another code — retain ONE global SKU identity; do not create a second global item. Source discrepancy retained in this report for audit.',
        ],
    ];
}

$pdo = Database::connection();
$pdo->exec("DELETE FROM unit_conversion_candidates WHERE sku IN ('999208','999209','140539','140541','140509','33515','140542','140543','140544','140545','140546','140547','140548','140549','140550','140551','900240','900251')");

$insert = $pdo->prepare(
    'INSERT INTO unit_conversion_candidates
        (sku, item_name, current_source, global_base_unit_candidate, legacy_base_unit, middle_unit_candidate,
         middle_conversion_to_base, purchase_unit_candidate, purchase_conversion_to_base,
         gudang_besar_display_unit, cibadak_display_unit, karangtengah_display_unit,
         name_derived_candidate, price_ratio_evidence, legacy_evidence, confidence, issue_code, issue_detail,
         review_status, approved_base_unit, approved_middle_unit, approved_middle_conversion,
         approved_purchase_unit, approved_purchase_conversion, approved, correction_note)
     VALUES
        (:sku, :item_name, :current_source, :global_base_unit_candidate, :legacy_base_unit, :middle_unit_candidate,
         :middle_conversion_to_base, :purchase_unit_candidate, :purchase_conversion_to_base,
         :gudang_besar_display_unit, :cibadak_display_unit, :karangtengah_display_unit,
         :name_derived_candidate, :price_ratio_evidence, :legacy_evidence, :confidence, :issue_code, :issue_detail,
         :review_status, :approved_base_unit, :approved_middle_unit, :approved_middle_conversion,
         :approved_purchase_unit, :approved_purchase_conversion, :approved, :correction_note)'
);

$results = [];
foreach (loadSampleInput() as $input) {
    $record = Engine::buildCandidateRecord($input);
    $results[] = $record;
    $insert->execute([
        'sku' => $record['sku'], 'item_name' => $record['item_name'], 'current_source' => $record['current_source'],
        'global_base_unit_candidate' => $record['global_base_unit_candidate'], 'legacy_base_unit' => $record['legacy_base_unit'],
        'middle_unit_candidate' => $record['middle_unit_candidate'], 'middle_conversion_to_base' => $record['middle_conversion_to_base'],
        'purchase_unit_candidate' => $record['purchase_unit_candidate'], 'purchase_conversion_to_base' => $record['purchase_conversion_to_base'],
        'gudang_besar_display_unit' => $record['gudang_besar_display_unit'], 'cibadak_display_unit' => $record['cibadak_display_unit'],
        'karangtengah_display_unit' => $record['karangtengah_display_unit'], 'name_derived_candidate' => $record['name_derived_candidate'],
        'price_ratio_evidence' => $record['price_ratio_evidence'] ? json_encode($record['price_ratio_evidence']) : null,
        'legacy_evidence' => $record['legacy_evidence'] ? json_encode($record['legacy_evidence']) : null,
        'confidence' => $record['confidence'], 'issue_code' => $record['issue_code'], 'issue_detail' => $record['issue_detail'],
        'review_status' => $record['review_status'],
        'approved_base_unit' => null, 'approved_middle_unit' => null, 'approved_middle_conversion' => null,
        'approved_purchase_unit' => null, 'approved_purchase_conversion' => null, 'approved' => null,
        'correction_note' => $record['correction_note'],
    ]);
}

echo "Processed " . count($results) . " SKU(s). Rows written to unit_conversion_candidates.\n";
foreach ($results as $r) {
    echo "  {$r['sku']}: issue_code=[{$r['issue_code']}] confidence=" . ($r['confidence'] ?? '-') . " review_status={$r['review_status']}\n";
}

// Dump JSON for the workbook generator (Python/openpyxl) to consume.
$outDir = __DIR__ . '/../migration/workspace/normalized';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
file_put_contents("{$outDir}/unit_conversion_candidates.json", json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nWrote {$outDir}/unit_conversion_candidates.json\n";
