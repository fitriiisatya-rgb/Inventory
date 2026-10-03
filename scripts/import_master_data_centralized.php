<?php
declare(strict_types=1);

/**
 * CENTRALIZED MASTER DATA — idempotent import/upsert for the business's
 * two source files (master_barang_*.xlsx, master_supplier_*.xlsx) into the
 * EXISTING, already-centralized `items`/`suppliers`/`categories`/
 * `item_unit_conversions`/`item_price_history` tables. See Task 1's audit
 * in the session report: no new table is created — items/suppliers are
 * already a single global master shared by SCM, Cibadak, and Karang
 * Tengah (no warehouse_id column on items at all), and INVENTORY_VIEW
 * (held by every warehouse's STOCK role) already grants read access to
 * all of it. This script only POPULATES that existing master, safely.
 *
 * READ-ONLY WITH RESPECT TO STOCK OPNAME: this script never references
 * stock_opname_sessions/stock_opname_lines/stock_opname_findings or any
 * other stock_opname_* table, never calls StockOpnameService, and never
 * touches inventory_batches/inventory_transactions. It writes ONLY to
 * items, item_unit_conversions, item_price_history, categories, suppliers.
 *
 * SAFETY RULES (idempotent, never a silent overwrite):
 *  - Items are matched by SKU (KODE BAHAN), case-sensitive exact match —
 *    the same identity key items.sku already uniquely enforces.
 *  - A brand-new SKU is created in full (base unit + conversions +
 *    category + supplier + status + minimum_stock), but ONLY if its
 *    base unit (SATUAN DASAR HPP) normalizes to a recognized unit
 *    (UnitNormalizationService — never guessed). If it does not, the row
 *    is skipped entirely and reported as an ERROR — nothing is created
 *    with an invented/guessed unit.
 *  - An EXISTING SKU is NEVER have its name/status/base_unit/
 *    minimum_stock overwritten by conflicting source data. Only BLANK/
 *    NULL fields are filled in (category_id, default_supplier_id,
 *    barcode), and minimum_stock is only raised off its untouched
 *    creation default of 0. A purchase/middle unit conversion is added
 *    only if the item has NO currently-open conversion for that unit at
 *    all; if one already exists with a DIFFERENT factor, it is left
 *    alone and reported as a mismatch for human review — never silently
 *    replaced (the same "never guess, never silently fix" rule
 *    ImportMasterItemService already follows elsewhere in this codebase).
 *  - Suppliers: this script ONLY creates a supplier record from a row
 *    that actually exists in the SUPPLIER MASTER FILE itself (matched/
 *    created by NAMA SUPPLIER). An item's DISTRIBUTOR column is used
 *    only to LINK to an already-known supplier (case-insensitive exact
 *    name match); a DISTRIBUTOR name that doesn't match any supplier-
 *    master row is NEVER auto-created as a new supplier — it is reported
 *    separately ("unmatched supplier names") for human review.
 *  - Unit cost / HPP: item_price_history is append-only history, so a
 *    price update for an EXISTING item is safe to apply going forward
 *    (ItemPriceService always reads the LATEST row) without touching any
 *    already-posted transaction's own snapshotted cost. A new row is
 *    inserted ONLY when the computed unit_cost_base differs from the
 *    item's current latest value (or none exists yet) — re-running this
 *    script with unchanged source data inserts zero new price rows the
 *    second time (idempotent).
 *  - unit_cost_base is computed as HARGA BELI / ISI DASAR, expressed per
 *    SATUAN DASAR HPP (base) unit — this is NOT a guess: it is exactly
 *    the same "price_per_unit / conversion_to_base" formula
 *    item_price_history.unit_cost_base is already documented to hold
 *    (database/schema.sql), with KEMASAN BELI/ISI DASAR being that
 *    purchase unit's own price/conversion pair. A row with HARGA BELI<=0,
 *    or whose KEMASAN BELI/ISI DASAR cannot be resolved to a valid
 *    purchase-unit conversion, is skipped and reported under
 *    "missing/zero price" — NEVER defaulted to a guessed value. In
 *    particular this script does NOT touch the deprecated "Rp650/PCS"
 *    figure some other context may reference — that is not present in
 *    either source file and is never used here.
 *  - STOK SAAT INI (SATUAN DASAR) / NILAI STOK (RP) in the barang file
 *    are the SOURCE system's own live stock snapshot (its own, possibly
 *    weighted-average, valuation) — this script NEVER reads or imports
 *    those two columns. Real stock/value in THIS system comes only from
 *    its own FIFO ledger (inventory_batches/inventory_transactions),
 *    which a master-data import must never touch or override.
 *
 * Known valid cost correction (explicitly authorized, applied as its own
 * clearly-logged step AFTER the general import, guarded to this one SKU):
 * RM-SP-26-027 "Penyedap Rasa Sasa" — the source file's own HARGA BELI is
 * 0 for this row (so the general pass reports it under missing-price,
 * exactly as it should), so the authoritative Rp53/GR reference price is
 * applied directly via the same item_price_history mechanism, never by
 * editing the general import's row-matching logic.
 *
 * Usage:
 *   php scripts/import_master_data_centralized.php <master_barang.xlsx> <master_supplier.xlsx> [--dry-run] [--reports-dir=PATH]
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';

use App\Services\Database;
use App\Services\XlsxReaderService;
use App\Services\UnitNormalizationService;
use App\Services\UnitConversionService;
use App\Services\AuditService;

// Same backdated convention ImportMasterItemService::createItem() already
// uses and documents: this is an EXISTING catalog being onboarded, not a
// brand-new conversion invented "today" — backdating keeps any historical/
// opening transaction dated before this import able to resolve a
// conversion that was already, in reality, in effect.
const MD_CONVERSION_VALID_FROM = '2000-01-01 00:00:00';
const MD_SYSTEM_USERNAME = 'master-data-import';

function md_slug_code(string $name, int $maxLen): string
{
    $slug = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name)));
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'X';
    }
    return mb_substr($slug, 0, $maxLen);
}

/** Generates a unique code for a new master row by appending -2, -3, ... on collision. */
function md_unique_code(PDO $pdo, string $table, string $baseName, int $maxLen): string
{
    $base = md_slug_code($baseName, $maxLen);
    $code = $base;
    $n = 2;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE code = :code");
    while (true) {
        $stmt->execute(['code' => $code]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $code;
        }
        $suffix = '-' . $n;
        $code = mb_substr($base, 0, max(1, $maxLen - mb_strlen($suffix))) . $suffix;
        $n++;
    }
}

/** @return array{imported:int[],updated:string[],skipped:string[],errors:string[],warnings:string[],unmatched_suppliers:array<string,string[]>,missing_price:string[],no_supplier_active:string[]} */
function md_run_import(PDO $pdo, string $barangPath, string $supplierPath, bool $dryRun): array
{
    $report = [
        'imported' => [], 'updated' => [], 'skipped' => [], 'errors' => [], 'warnings' => [],
        'unmatched_suppliers' => [], // supplier_name => [skus]
        'missing_price' => [],       // sku => reason
        'no_supplier_active' => [],  // sku (ACTIVE, no default_supplier_id after this run)
        'price_updated' => [],       // sku => old/new unit_cost_base
        'duplicate_sku_in_file' => [],
        'invalid_unit_items' => [],  // sku => which unit field + raw value
    ];

    // ---- Pass 1: supplier master file -> upsert suppliers by NAME ----
    $supplierRows = XlsxReaderService::read($supplierPath);
    $supplierIdByName = []; // lowercased trimmed name => supplier id
    $suppliersCreated = 0;
    $suppliersReused = 0;
    foreach ($supplierRows as $row) {
        $name = trim((string) ($row['NAMA SUPPLIER'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = mb_strtolower($name);
        if (isset($supplierIdByName[$key])) {
            continue; // duplicate name within the supplier file itself — first occurrence wins
        }
        $existing = $pdo->prepare('SELECT id FROM suppliers WHERE LOWER(name) = :n');
        $existing->execute(['n' => $key]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            $supplierIdByName[$key] = (int) $id;
            $suppliersReused++;
            continue;
        }
        if ($dryRun) {
            $supplierIdByName[$key] = -1;
            $suppliersCreated++;
            continue;
        }
        $code = md_unique_code($pdo, 'suppliers', $name, 30);
        $pdo->prepare('INSERT INTO suppliers (code, name, is_active) VALUES (:c, :n, 1)')
            ->execute(['c' => $code, 'n' => $name]);
        $newId = (int) $pdo->lastInsertId();
        $supplierIdByName[$key] = $newId;
        $suppliersCreated++;
        AuditService::log($pdo, null, MD_SYSTEM_USERNAME, 'SUPPLIER_CREATE', 'suppliers', $newId, null, ['code' => $code, 'name' => $name], 'centralized master data import');
    }
    $report['suppliers_created'] = $suppliersCreated;
    $report['suppliers_reused'] = $suppliersReused;
    $report['suppliers_in_file'] = count($supplierIdByName);

    // ---- Pass 2: item master file -> upsert items ----
    $itemRows = XlsxReaderService::read($barangPath);
    $categoryIdByName = [];
    $seenSkuInFile = [];
    $itemsCreated = 0;
    $itemsUpdated = 0;
    $itemsSkippedNoBaseUnit = 0;

    foreach ($itemRows as $rowNum => $row) {
        $sku = trim((string) ($row['KODE BAHAN'] ?? ''));
        $name = trim((string) ($row['NAMA BAHAN'] ?? ''));
        $barcode = trim((string) ($row['BARCODE'] ?? ''));
        $categoryName = trim((string) ($row['KATEGORI'] ?? ''));
        $brand = trim((string) ($row['MERK'] ?? ''));
        $supplierName = trim((string) ($row['DISTRIBUTOR'] ?? ''));
        $baseUnitRaw = trim((string) ($row['SATUAN DASAR HPP'] ?? ''));
        $purchaseUnitRaw = trim((string) ($row['KEMASAN BELI'] ?? ''));
        $purchaseConv = trim((string) ($row['ISI DASAR'] ?? ''));
        $middleUnitRaw = trim((string) ($row['SATUAN KEMASAN'] ?? ''));
        $middleConv = trim((string) ($row['ISI KEMASAN'] ?? ''));
        $statusRaw = trim((string) ($row['STATUS'] ?? ''));
        $minimumStockRaw = trim((string) ($row['STOK MINIMUM'] ?? ''));
        $hargaBeli = trim((string) ($row['HARGA BELI'] ?? ''));
        $notes = trim((string) ($row['KETERANGAN'] ?? ''));

        if ($sku === '') {
            $report['errors'][] = "row {$rowNum}: KODE BAHAN (sku) is blank — skipped";
            continue;
        }
        if (isset($seenSkuInFile[$sku])) {
            $report['duplicate_sku_in_file'][] = $sku;
            $report['errors'][] = "row {$rowNum}: duplicate KODE BAHAN within source file: {$sku} — only the first occurrence is used";
            continue;
        }
        $seenSkuInFile[$sku] = true;
        if ($name === '') {
            $report['errors'][] = "{$sku}: NAMA BAHAN (name) is blank — skipped";
            continue;
        }

        $baseUnitCode = $baseUnitRaw !== '' ? UnitNormalizationService::normalize($pdo, $baseUnitRaw) : null;

        $existingStmt = $pdo->prepare('SELECT * FROM items WHERE sku = :sku');
        $existingStmt->execute(['sku' => $sku]);
        $existing = $existingStmt->fetch();

        $status = match (mb_strtolower($statusRaw)) {
            'aktif' => 'ACTIVE',
            'tidak aktif' => 'INACTIVE',
            default => null,
        };
        if ($status === null) {
            $report['warnings'][] = "{$sku}: STATUS '{$statusRaw}' not recognized, defaulting to ACTIVE";
            $status = 'ACTIVE';
        }

        // ---- resolve supplier link (never auto-creates from DISTRIBUTOR) ----
        $resolvedSupplierId = null;
        if ($supplierName !== '') {
            $key = mb_strtolower($supplierName);
            if (isset($supplierIdByName[$key])) {
                $resolvedSupplierId = $supplierIdByName[$key];
            } else {
                $report['unmatched_suppliers'][$supplierName][] = $sku;
            }
        }

        // ---- resolve/create category (supported directly by this row's own data) ----
        $resolvedCategoryId = null;
        if ($categoryName !== '') {
            $catKey = mb_strtolower($categoryName);
            if (isset($categoryIdByName[$catKey])) {
                $resolvedCategoryId = $categoryIdByName[$catKey];
            } else {
                $catExisting = $pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = :n');
                $catExisting->execute(['n' => $catKey]);
                $catId = $catExisting->fetchColumn();
                if ($catId !== false) {
                    $resolvedCategoryId = (int) $catId;
                } elseif (!$dryRun) {
                    $code = md_unique_code($pdo, 'categories', $categoryName, 60);
                    $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => $code, 'n' => $categoryName]);
                    $resolvedCategoryId = (int) $pdo->lastInsertId();
                } else {
                    $resolvedCategoryId = -1;
                }
                $categoryIdByName[$catKey] = $resolvedCategoryId;
            }
        }

        if ($existing === false) {
            // ---- CREATE new item ----
            // NOTE: every lookup below (normalize/resolveUnitId) is a
            // read-only query, so the SAME resolution logic runs whether
            // or not --dry-run is set — only the actual INSERT/
            // openNewVersion/AuditService::log calls are skipped in a dry
            // run, so the printed/written report is a faithful preview of
            // what a live run would do, never a shortcut approximation.
            if ($baseUnitCode === null) {
                $report['skipped'][] = "{$sku}: cannot create — base unit '{$baseUnitRaw}' not recognized";
                $report['invalid_unit_items'][$sku] = "base_unit='{$baseUnitRaw}'";
                $itemsSkippedNoBaseUnit++;
                continue;
            }

            $baseUnitId = UnitNormalizationService::resolveUnitId($pdo, $baseUnitCode);
            $itemId = -1;
            if (!$dryRun) {
                $pdo->prepare(
                    'INSERT INTO items (sku, barcode, name, category, category_id, brand, base_unit_id, minimum_stock, default_supplier_id, notes, status, created_at, updated_at)
                     VALUES (:sku, :barcode, :name, :category, :category_id, :brand, :base_unit_id, :min_stock, :supplier_id, :notes, :status, NOW(), NOW())'
                )->execute([
                    'sku' => $sku, 'barcode' => $barcode !== '' ? $barcode : null, 'name' => $name,
                    'category' => $categoryName !== '' ? $categoryName : null,
                    'category_id' => $resolvedCategoryId > 0 ? $resolvedCategoryId : null,
                    'brand' => $brand !== '' ? $brand : null, 'base_unit_id' => $baseUnitId,
                    'min_stock' => $minimumStockRaw !== '' ? (float) $minimumStockRaw : 0,
                    'supplier_id' => $resolvedSupplierId, 'notes' => $notes !== '' ? $notes : null, 'status' => $status,
                ]);
                $itemId = (int) $pdo->lastInsertId();
                UnitConversionService::openNewVersion($pdo, $itemId, $baseUnitId, 1.0, MD_CONVERSION_VALID_FROM, null, 'identity (base unit) — centralized master data import');
            }

            $purchaseUnitId = null;
            if ($purchaseUnitRaw !== '' && (float) $purchaseConv > 0) {
                $purchaseCode = UnitNormalizationService::normalize($pdo, $purchaseUnitRaw);
                if ($purchaseCode !== null) {
                    $purchaseUnitId = UnitNormalizationService::resolveUnitId($pdo, $purchaseCode);
                    if ($purchaseUnitId !== $baseUnitId && !$dryRun) {
                        UnitConversionService::openNewVersion($pdo, $itemId, $purchaseUnitId, (float) $purchaseConv, MD_CONVERSION_VALID_FROM, null, 'purchase unit — centralized master data import', true);
                    }
                } else {
                    $report['warnings'][] = "{$sku}: purchase unit '{$purchaseUnitRaw}' not recognized — conversion not created";
                    $report['invalid_unit_items'][$sku] = ($report['invalid_unit_items'][$sku] ?? '') . " purchase_unit='{$purchaseUnitRaw}'";
                }
            }
            if ($middleUnitRaw !== '' && (float) $middleConv > 0 && !$dryRun) {
                $middleCode = UnitNormalizationService::normalize($pdo, $middleUnitRaw);
                if ($middleCode !== null) {
                    $middleUnitId = UnitNormalizationService::resolveUnitId($pdo, $middleCode);
                    if ($middleUnitId !== $baseUnitId && $middleUnitId !== $purchaseUnitId) {
                        UnitConversionService::openNewVersion($pdo, $itemId, $middleUnitId, (float) $middleConv, MD_CONVERSION_VALID_FROM, null, 'middle unit — centralized master data import');
                    }
                } else {
                    $report['warnings'][] = "{$sku}: middle unit '{$middleUnitRaw}' not recognized — conversion not created";
                }
            }

            // A brand-new item has no price history yet either way, so the
            // dry-run preview of md_apply_price() (which only ever READS
            // item_price_history to decide "already up to date") is exact
            // even with the placeholder itemId=-1 — nothing is queried
            // against a real row in the dry-run path (price>0 check and
            // unit validity are both pure/no-DB), and the $dryRun flag
            // itself gates the actual INSERT.
            md_apply_price($pdo, $report, $sku, $itemId, $baseUnitId, $purchaseUnitId, $purchaseConv, $hargaBeli, $dryRun);

            if (!$dryRun) {
                AuditService::log($pdo, null, MD_SYSTEM_USERNAME, 'ITEM_CREATE', 'items', $itemId, null, ['sku' => $sku, 'name' => $name], 'centralized master data import');
            }
            $report['imported'][] = $sku;
            $itemsCreated++;
        } else {
            // ---- UPDATE existing item: fill-blank-only policy, never overwrite a set value ----
            $itemId = (int) $existing['id'];
            $changes = [];
            $fields = [];
            if ($existing['category_id'] === null && $resolvedCategoryId !== null && $resolvedCategoryId > 0) {
                $fields['category_id'] = $resolvedCategoryId;
                $fields['category'] = $categoryName;
                $changes[] = 'category';
            }
            if ($existing['default_supplier_id'] === null && $resolvedSupplierId !== null) {
                $fields['default_supplier_id'] = $resolvedSupplierId;
                $changes[] = 'default_supplier_id';
            }
            if (($existing['barcode'] === null || trim((string) $existing['barcode']) === '') && $barcode !== '') {
                $fields['barcode'] = $barcode;
                $changes[] = 'barcode';
            }
            if ((float) $existing['minimum_stock'] === 0.0 && $minimumStockRaw !== '' && (float) $minimumStockRaw > 0) {
                $fields['minimum_stock'] = (float) $minimumStockRaw;
                $changes[] = 'minimum_stock';
            }

            if ($fields !== [] && !$dryRun) {
                $set = implode(', ', array_map(static fn ($k) => "{$k} = :{$k}", array_keys($fields)));
                $pdo->prepare("UPDATE items SET {$set}, updated_at = NOW() WHERE id = :id")
                    ->execute($fields + ['id' => $itemId]);
                AuditService::log($pdo, null, MD_SYSTEM_USERNAME, 'ITEM_UPDATE', 'items', $itemId, null, $fields, 'centralized master data import — fill-blank-only');
            }

            // Conversions: only ADD if no open conversion exists yet for that
            // unit; a differing existing conversion is reported, never replaced.
            if ($baseUnitCode !== null) {
                $dbBaseUnit = $pdo->prepare('SELECT code FROM units WHERE id = :id');
                $dbBaseUnit->execute(['id' => $existing['base_unit_id']]);
                $dbBaseUnitCode = $dbBaseUnit->fetchColumn();
                if ($dbBaseUnitCode !== false && $dbBaseUnitCode !== $baseUnitCode) {
                    $report['warnings'][] = "{$sku}: source base unit '{$baseUnitRaw}' ({$baseUnitCode}) differs from existing item's base unit ({$dbBaseUnitCode}) — NOT changed (base unit is immutable once set); needs human review";
                }
            } elseif ($baseUnitRaw !== '') {
                $report['invalid_unit_items'][$sku] = "base_unit='{$baseUnitRaw}'";
            }

            $purchaseUnitId = null;
            if ($purchaseUnitRaw !== '' && (float) $purchaseConv > 0) {
                $purchaseCode = UnitNormalizationService::normalize($pdo, $purchaseUnitRaw);
                if ($purchaseCode !== null) {
                    $purchaseUnitId = UnitNormalizationService::resolveUnitId($pdo, $purchaseCode);
                    $openConv = UnitConversionService::getActiveConversion($pdo, $itemId, $purchaseUnitId, date('Y-m-d H:i:s'));
                    if ($openConv === null) {
                        if (!$dryRun) {
                            UnitConversionService::openNewVersion($pdo, $itemId, $purchaseUnitId, (float) $purchaseConv, MD_CONVERSION_VALID_FROM, null, 'purchase unit — centralized master data import (added to existing item)', true);
                        }
                        $changes[] = 'purchase_unit_conversion_added';
                    } elseif (abs((float) $openConv['conversion_to_base'] - (float) $purchaseConv) > 0.0000001) {
                        $report['warnings'][] = "{$sku}: source purchase conversion ({$purchaseUnitRaw}={$purchaseConv}) differs from existing active conversion ({$openConv['conversion_to_base']}) — NOT changed, needs human review";
                    }
                } else {
                    $report['warnings'][] = "{$sku}: purchase unit '{$purchaseUnitRaw}' not recognized — conversion not touched";
                }
            }

            md_apply_price($pdo, $report, $sku, $itemId, (int) $existing['base_unit_id'], $purchaseUnitId, $purchaseConv, $hargaBeli, $dryRun);

            if ($changes !== []) {
                $report['updated'][] = "{$sku}: " . implode(', ', $changes);
                $itemsUpdated++;
            }
        }
    }

    $report['items_created'] = $itemsCreated;
    $report['items_updated'] = $itemsUpdated;
    $report['items_skipped_no_base_unit'] = $itemsSkippedNoBaseUnit;

    // ---- active items without a supplier (after this run) ----
    if (!$dryRun) {
        $noSupplierStmt = $pdo->query(
            "SELECT sku FROM items WHERE status = 'ACTIVE' AND default_supplier_id IS NULL ORDER BY sku"
        );
        $report['no_supplier_active'] = array_column($noSupplierStmt->fetchAll(), 'sku');
    }

    return $report;
}

/** unit_cost_base = HARGA BELI / ISI DASAR, per base unit — see this file's own docblock. */
function md_apply_price(PDO $pdo, array &$report, string $sku, int $itemId, int $baseUnitId, ?int $purchaseUnitId, string $purchaseConv, string $hargaBeli, bool $dryRun): void
{
    $price = (float) $hargaBeli;
    $conv = (float) $purchaseConv;
    if ($price <= 0) {
        $report['missing_price'][] = "{$sku}: HARGA BELI is 0/blank in source — no price applied";
        return;
    }
    if ($purchaseUnitId === null || $conv <= 0) {
        $report['missing_price'][] = "{$sku}: HARGA BELI={$price} present but purchase unit/conversion is invalid — cannot safely derive unit_cost_base, no price applied";
        return;
    }

    $unitCostBase = round($price / $conv, 4);

    $latest = $pdo->prepare(
        'SELECT unit_cost_base FROM item_price_history WHERE item_id = :item_id ORDER BY effective_date DESC, id DESC LIMIT 1'
    );
    $latest->execute(['item_id' => $itemId]);
    $currentLatest = $latest->fetchColumn();

    if ($currentLatest !== false && abs((float) $currentLatest - $unitCostBase) < 0.0001) {
        return; // idempotent no-op: already up to date
    }

    if ($dryRun) {
        $report['price_updated'][] = "{$sku}: " . ($currentLatest === false ? '(none)' : $currentLatest) . " -> {$unitCostBase} per base unit [DRY RUN]";
        return;
    }

    $pdo->prepare(
        'INSERT INTO item_price_history (item_id, supplier_id, unit_id, price_per_unit, unit_cost_base, effective_date, created_at)
         VALUES (:item_id, NULL, :unit_id, :price_per_unit, :unit_cost_base, NOW(), NOW())'
    )->execute([
        'item_id' => $itemId, 'unit_id' => $purchaseUnitId,
        'price_per_unit' => $price, 'unit_cost_base' => $unitCostBase,
    ]);
    $report['price_updated'][] = "{$sku}: " . ($currentLatest === false ? '(none)' : $currentLatest) . " -> {$unitCostBase} per base unit";
}

/**
 * Known valid cost correction (Task 4) — guarded to this one SKU only,
 * applied as its own explicit step so it can never be confused with (or
 * silently triggered by) the general row-matching import logic above.
 */
function md_apply_known_corrections(PDO $pdo, bool $dryRun): array
{
    $corrections = [
        // sku => [unit_code, unit_cost_base, reason]
        'RM-SP-26-027' => ['GR', 53.00, 'Penyedap Rasa Sasa — reference price Rp53.000/KG = Rp53/GR; source file HARGA BELI was 0/missing'],
    ];
    $applied = [];
    foreach ($corrections as $sku => [$unitCode, $cost, $reason]) {
        $item = $pdo->prepare('SELECT i.id, u.code AS base_unit_code FROM items i JOIN units u ON u.id = i.base_unit_id WHERE i.sku = :sku');
        $item->execute(['sku' => $sku]);
        $row = $item->fetch();
        if ($row === false) {
            $applied[] = "{$sku}: SKIPPED — item not found (import must run first)";
            continue;
        }
        if ($row['base_unit_code'] !== $unitCode) {
            $applied[] = "{$sku}: SKIPPED — expected base unit {$unitCode}, found {$row['base_unit_code']} (never overriding base unit automatically)";
            continue;
        }
        $itemId = (int) $row['id'];
        $baseUnitStmt = $pdo->prepare('SELECT id FROM units WHERE code = :c');
        $baseUnitStmt->execute(['c' => $unitCode]);
        $baseUnitId = (int) $baseUnitStmt->fetchColumn();

        $latest = $pdo->prepare('SELECT unit_cost_base FROM item_price_history WHERE item_id = :id ORDER BY effective_date DESC, id DESC LIMIT 1');
        $latest->execute(['id' => $itemId]);
        $currentLatest = $latest->fetchColumn();
        if ($currentLatest !== false && abs((float) $currentLatest - $cost) < 0.0001) {
            $applied[] = "{$sku}: already at Rp{$cost}/{$unitCode} — no-op";
            continue;
        }
        if ($dryRun) {
            $applied[] = "{$sku}: would correct to Rp{$cost}/{$unitCode} [DRY RUN] ({$reason})";
            continue;
        }
        $pdo->prepare(
            'INSERT INTO item_price_history (item_id, supplier_id, unit_id, price_per_unit, unit_cost_base, effective_date, created_at)
             VALUES (:item_id, NULL, :unit_id, :price_per_unit, :unit_cost_base, NOW(), NOW())'
        )->execute(['item_id' => $itemId, 'unit_id' => $baseUnitId, 'price_per_unit' => $cost, 'unit_cost_base' => $cost]);
        AuditService::log($pdo, null, MD_SYSTEM_USERNAME, 'ITEM_PRICE_MANUAL_CORRECTION', 'items', $itemId, ['unit_cost_base' => $currentLatest ?: null], ['unit_cost_base' => $cost], $reason);
        $applied[] = "{$sku}: corrected to Rp{$cost}/{$unitCode} ({$reason})";
    }
    return $applied;
}

// ============================================================
// CLI entry point
// ============================================================
// Guards the CLI body to run only when this file is the script actually
// invoked (`php scripts/import_master_data_centralized.php ...`), never
// when it is require_once'd by a test (also CLI SAPI) purely to reuse
// md_run_import()/md_apply_known_corrections() as functions.
if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$args = array_slice($argv, 1);
$positional = [];
$dryRun = false;
$reportsDir = null;
foreach ($args as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--reports-dir=')) {
        $reportsDir = substr($arg, strlen('--reports-dir='));
    } else {
        $positional[] = $arg;
    }
}

if (count($positional) < 2) {
    fwrite(STDERR, "Usage: php scripts/import_master_data_centralized.php <master_barang.xlsx> <master_supplier.xlsx> [--dry-run] [--reports-dir=PATH]\n");
    exit(1);
}
[$barangPath, $supplierPath] = $positional;
if (!file_exists($barangPath) || !file_exists($supplierPath)) {
    fwrite(STDERR, "One or both input files not found.\n");
    exit(1);
}

$pdo = Database::connection();
echo "Connected via driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
echo $dryRun ? "--dry-run: no changes will be made.\n\n" : "LIVE RUN — changes will be committed.\n\n";

$report = $dryRun
    ? md_run_import($pdo, $barangPath, $supplierPath, true)
    : Database::transaction(fn (PDO $tx) => md_run_import($tx, $barangPath, $supplierPath, false));

$corrections = $dryRun
    ? md_apply_known_corrections($pdo, true)
    : Database::transaction(fn (PDO $tx) => md_apply_known_corrections($tx, false));

echo "== SUPPLIERS ==\n";
echo "In source file: {$report['suppliers_in_file']} | created: {$report['suppliers_created']} | reused: {$report['suppliers_reused']}\n\n";

echo "== ITEMS ==\n";
echo "Created: {$report['items_created']} | Updated: {$report['items_updated']} | Skipped (no valid base unit): {$report['items_skipped_no_base_unit']}\n";
echo "Errors: " . count($report['errors']) . " | Warnings: " . count($report['warnings']) . "\n\n";

echo "== KNOWN COST CORRECTIONS ==\n";
foreach ($corrections as $line) {
    echo " - {$line}\n";
}
echo "\n";

echo "== UNMATCHED SUPPLIER NAMES (" . count($report['unmatched_suppliers']) . ") ==\n";
foreach ($report['unmatched_suppliers'] as $name => $skus) {
    echo " - \"{$name}\" referenced by " . count($skus) . " item(s): " . implode(', ', array_slice($skus, 0, 5)) . (count($skus) > 5 ? '…' : '') . "\n";
}
echo "\n";

echo "== MISSING/ZERO PRICE (" . count($report['missing_price']) . ") ==\n";
foreach (array_slice($report['missing_price'], 0, 20) as $line) {
    echo " - {$line}\n";
}
if (count($report['missing_price']) > 20) {
    echo ' - … and ' . (count($report['missing_price']) - 20) . " more (see report file)\n";
}
echo "\n";

echo "== ACTIVE ITEMS WITHOUT SUPPLIER (" . count($report['no_supplier_active']) . ") ==\n\n";

if ($reportsDir !== null) {
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0755, true);
    }
    foreach (['imported', 'updated', 'skipped', 'errors', 'warnings', 'missing_price', 'no_supplier_active', 'price_updated', 'duplicate_sku_in_file'] as $key) {
        file_put_contents("{$reportsDir}/{$key}.txt", implode("\n", $report[$key]) . "\n");
    }
    $unmatchedLines = [];
    foreach ($report['unmatched_suppliers'] as $name => $skus) {
        $unmatchedLines[] = "{$name}\t" . implode(',', $skus);
    }
    file_put_contents("{$reportsDir}/unmatched_suppliers.tsv", "supplier_name\tskus\n" . implode("\n", $unmatchedLines) . "\n");
    file_put_contents("{$reportsDir}/invalid_unit_items.tsv", "sku\tdetail\n" . implode("\n", array_map(
        static fn ($sku, $detail) => "{$sku}\t{$detail}",
        array_keys($report['invalid_unit_items']), $report['invalid_unit_items']
    )) . "\n");
    file_put_contents("{$reportsDir}/known_corrections.txt", implode("\n", $corrections) . "\n");
    echo "Full reports written to: {$reportsDir}\n";
}

echo "\nDone.\n";
