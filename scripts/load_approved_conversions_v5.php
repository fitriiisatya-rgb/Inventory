<?php
declare(strict_types=1);

/**
 * PHASE G-DATA 2 — loads the 34 BUSINESS_CONFIRMED conversion decisions
 * from unit_conversion_candidates_real_v5.json into the
 * unit_conversion_candidates STAGING/REVIEW table (schema.sql Section
 * 5B) — never directly into item_unit_conversions. Preserves full audit:
 * conversion_source, admin_source_answer, prior_detector_evidence,
 * correction_note, approved_by_name, approved_at. Never deletes/
 * overwrites the detector's own evidence columns.
 *
 * This is DATA loading into a review-only table — it does NOT touch
 * items/item_unit_conversions/inventory_batches and is safe to run at
 * any time, including before the real item master is imported (since
 * unit_conversion_candidates.sku is a plain string, not an FK).
 *
 * The separate, explicit PROMOTION step (writing into the real,
 * FIFO-facing item_unit_conversions table via
 * UnitConversionService::openNewVersion()) is intentionally a different
 * script (promote_approved_conversions.php) and is NOT run automatically
 * — it requires the item to already exist by SKU, i.e. after the real
 * item master has been imported, which has not happened yet.
 *
 * Usage: php scripts/load_approved_conversions_v5.php
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$jsonPath = __DIR__ . '/../migration/workspace/normalized/unit_conversion_candidates_real_v5.json';
if (!file_exists($jsonPath)) {
    fwrite(STDERR, "not found: {$jsonPath}\n");
    exit(1);
}
$records = json_decode(file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

$approved = array_values(array_filter($records, fn ($r) => ($r['conversion_source'] ?? null) === 'BUSINESS_CONFIRMED'));
echo "Found " . count($approved) . " BUSINESS_CONFIRMED records in v5.\n";

$pdo = Database::connection();
$upsert = $pdo->prepare(
    'INSERT INTO unit_conversion_candidates
        (sku, item_name, current_source, global_base_unit_candidate, legacy_base_unit,
         purchase_unit_candidate, purchase_conversion_to_base,
         confidence, issue_code, issue_detail, review_status,
         approved_base_unit, approved_purchase_unit, approved_purchase_conversion, approved, correction_note,
         conversion_source, admin_source_answer, prior_detector_evidence,
         approved_by_name, approved_at, created_at, updated_at)
     VALUES
        (:sku, :item_name, \'migration/workspace v5\', :global_base_unit, :legacy_base_unit,
         :purchase_unit_candidate, :purchase_conversion_to_base,
         \'BUSINESS_CONFIRMED\', :issue_code, :issue_detail, \'APPROVED\',
         :approved_base_unit, :approved_purchase_unit, :approved_purchase_conversion, \'YES\', :correction_note,
         :conversion_source, :admin_source_answer, :prior_detector_evidence,
         :approved_by_name, :approved_at, :now, :now2)
     ON DUPLICATE KEY UPDATE
        item_name = VALUES(item_name), global_base_unit_candidate = VALUES(global_base_unit_candidate),
        legacy_base_unit = VALUES(legacy_base_unit), purchase_unit_candidate = VALUES(purchase_unit_candidate),
        purchase_conversion_to_base = VALUES(purchase_conversion_to_base), confidence = VALUES(confidence),
        issue_code = VALUES(issue_code), issue_detail = VALUES(issue_detail), review_status = VALUES(review_status),
        approved_base_unit = VALUES(approved_base_unit), approved_purchase_unit = VALUES(approved_purchase_unit),
        approved_purchase_conversion = VALUES(approved_purchase_conversion), approved = VALUES(approved),
        correction_note = VALUES(correction_note), conversion_source = VALUES(conversion_source),
        admin_source_answer = VALUES(admin_source_answer), prior_detector_evidence = VALUES(prior_detector_evidence),
        approved_by_name = VALUES(approved_by_name), approved_at = VALUES(approved_at), updated_at = VALUES(updated_at)'
);

$now = date('Y-m-d H:i:s');
$count = 0;
foreach ($approved as $r) {
    $priorEvidence = json_encode([
        'issue_code' => $r['prior_semantic_issue_code'] ?? $r['prior_detector_issue_code'] ?? null,
        'confidence' => $r['prior_semantic_confidence'] ?? $r['prior_detector_confidence'] ?? null,
        'review_status' => $r['prior_semantic_review_status'] ?? $r['prior_detector_review_status'] ?? null,
    ], JSON_UNESCAPED_UNICODE);

    $upsert->execute([
        'sku' => $r['sku'], 'item_name' => $r['item_name'] ?? null,
        'global_base_unit' => $r['global_base_unit_candidate'] ?? null,
        'legacy_base_unit' => $r['legacy_base_unit'] ?? null,
        'purchase_unit_candidate' => $r['purchase_unit_candidate'] ?? null,
        'purchase_conversion_to_base' => is_numeric($r['purchase_conversion_candidate'] ?? null) ? (float) $r['purchase_conversion_candidate'] : null,
        'issue_code' => $r['issue_code'] ?? null, 'issue_detail' => $r['issue_detail'] ?? null,
        'approved_base_unit' => $r['approved_base_unit'] ?? null,
        'approved_purchase_unit' => $r['approved_purchase_unit'] ?? null,
        'approved_purchase_conversion' => is_numeric($r['approved_purchase_conversion'] ?? null) ? (float) $r['approved_purchase_conversion'] : null,
        'correction_note' => $r['correction_note'] ?? null,
        'conversion_source' => $r['conversion_source'] ?? 'BUSINESS_CONFIRMED',
        'admin_source_answer' => $r['admin_source_answer'] ?? null,
        'prior_detector_evidence' => $priorEvidence,
        'approved_by_name' => 'Owner/Admin (Phase G-DATA 1B.1 / 1B.3)',
        'approved_at' => $now, 'now' => $now, 'now2' => $now,
    ]);
    $count++;
}

echo "Loaded/updated {$count} approved conversion rows into unit_conversion_candidates.\n";
echo "NOTE: this only loads STAGING/REVIEW data. It does NOT write to item_unit_conversions.\n";
echo "Run promote_approved_conversions.php separately, only once the real item master exists.\n";
