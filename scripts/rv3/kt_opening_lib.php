<?php
declare(strict_types=1);

/**
 * Karang Tengah OPENING BALANCE (cutover) — library for scripts/rv3/kt_opening.php. One controlled, auditable, idempotent process:
 *
 *   plan    read-only. The physical Stock Opname workbook (one row per kode barang) is mapped to Master Barang by kode barang == items.sku, the
 *           source UoM is resolved against the item's base unit / item_unit_conversions (CASE NORMALISED FOR COMPARISON ONLY — nothing is
 *           created, guessed or hard-coded), the approved unit HPP is converted inversely to the base unit so that
 *               source opening value == normalised qty x normalised unit HPP   (rounding tolerance only), and every row gets exactly one status.
 *   post    ONE database transaction. One FifoService::postIn() per positive row, transaction_type OPENING (the existing opening/cutover
 *           ledger type: it is part of the opening balance, never Purchase / Stock IN / Transfer / Adjustment), transaction_date = the effective
 *           date 2026-10-01 00:00:00 (the report boundary rule counts it in the October opening), posting_date / created_at = the real
 *           posting time, reference_no = KARANG_TENGAH_SO_20261001. Bound to the reviewed preview by its SHA256, refused while any BLOCKER exists,
 *           refused when already posted (OPENING_BALANCE_ALREADY_POSTED), verified inside the transaction (a mismatch rolls everything back).
 *   verify  read-only post-write reconciliation (transaction == on-hand == FIFO layer, value, warehouse total, report classification).
 *
 * It never touches Stock Opname sessions, existing FIFO layers, other warehouses, purchase / transfer history or the effective-date override table.
 * No shell, no child process, no symlink, no temp file.
 */

use App\Services\AuditService;
use App\Services\Database;
use App\Services\FifoService;
use App\Services\XlsxReaderService;

const KT_REFERENCE = 'KARANG_TENGAH_SO_20261001';
const KT_EFFECTIVE_DATE = '2026-10-01';
const KT_WAREHOUSE_CODE = 'KARANG_TENGAH';
const KT_UUID_PREFIX = 'KT-OPENING-20261001-';
/** Only rounding is tolerated: FIFO stores qty at 6 dp and unit cost at 4 dp. Anything above these is a real discrepancy and BLOCKS posting. */
const KT_LINE_TOLERANCE = 0.50;
const KT_TOTAL_TOLERANCE = 5.00;

// ============================================================================ source
/** @return ?float null = not a number */
function kt_num(string $s): ?float
{
    $s = trim(str_replace(["\xc2\xa0", ' '], '', $s));
    if ($s === '') {
        return null;
    }
    return is_numeric($s) ? (float) $s : null;
}

function kt_norm_unit(string $u): string
{
    return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $u)), 'UTF-8');
}

/**
 * @return array{file:string,sha256:string,header_row:int,declared_total:?float,rows:list<array<string,mixed>>,errors:list<string>}
 */
function kt_read_source(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("source file not found: {$path}");
    }
    $grid = XlsxReaderService::readRawGrid($path, null);
    ksort($grid);
    $need = ['item' => 'item', 'code' => 'kode barang', 'uom' => 'uom', 'price' => 'harga', 'prod' => 'stock akhir produksi', 'wh' => 'stock akhir gudang', 'total' => 'jumlah'];
    $headerRow = null;
    $cols = [];
    foreach ($grid as $rn => $cells) {
        $byName = [];
        foreach ($cells as $letter => $v) {
            $byName[mb_strtolower(trim((string) $v), 'UTF-8')] = $letter;
        }
        if (isset($byName['kode barang'])) {
            $headerRow = (int) $rn;
            foreach ($need as $k => $label) {
                $cols[$k] = $byName[$label] ?? null;
            }
            break;
        }
    }
    if ($headerRow === null) {
        throw new RuntimeException('header row not found (expected a column "kode barang")');
    }
    foreach (['code', 'uom', 'price', 'prod', 'wh'] as $k) {
        if ($cols[$k] === null) {
            throw new RuntimeException("source column missing: {$need[$k]}");
        }
    }
    // the workbook states its own control total in the row above the header (column "Jumlah")
    $declared = null;
    foreach ($grid as $rn => $cells) {
        if ($rn < $headerRow && $cols['total'] !== null && isset($cells[$cols['total']]) && kt_num((string) $cells[$cols['total']]) !== null) {
            $declared = kt_num((string) $cells[$cols['total']]);
        }
    }
    // the title lines above the header ("SO Nominal Gudang Kecil", "Area : …", "Periode Juli 2026") are kept as AUDIT METADATA only — never as the inventory effective date
    $titleLines = [];
    foreach ($grid as $rn => $cells) {
        if ($rn >= $headerRow) {
            break;
        }
        foreach ($cells as $v) {
            $t = trim((string) $v);
            if ($t !== '' && kt_num($t) === null) {
                $titleLines[] = $t;
            }
        }
    }
    $rows = [];
    $errors = [];
    foreach ($grid as $rn => $cells) {
        if ($rn <= $headerRow) {
            continue;
        }
        $g = static fn (?string $c): string => $c === null ? '' : trim((string) ($cells[$c] ?? ''));
        $code = $g($cols['code']);
        $name = $g($cols['item'] ?? null);
        $uom = $g($cols['uom']);
        $priceRaw = $g($cols['price']);
        $prodRaw = $g($cols['prod']);
        $whRaw = $g($cols['wh']);
        if ($code === '' && $name === '' && $uom === '' && $priceRaw === '' && $prodRaw === '' && $whRaw === '') {
            continue;   // blank trailing / spacer row
        }
        $prod = $prodRaw === '' ? 0.0 : kt_num($prodRaw);
        $wh = $whRaw === '' ? 0.0 : kt_num($whRaw);
        $price = $priceRaw === '' ? null : kt_num($priceRaw);
        $invalid = [];
        if ($prod === null) {
            $invalid[] = "Stock Akhir Produksi bukan angka ({$prodRaw})";
        }
        if ($wh === null) {
            $invalid[] = "Stock Akhir Gudang bukan angka ({$whRaw})";
        }
        if ($priceRaw !== '' && $price === null) {
            $invalid[] = "HARGA bukan angka ({$priceRaw})";
        }
        if (($prod ?? 0) < 0 || ($wh ?? 0) < 0) {
            $invalid[] = 'qty negatif';
        }
        if ($price !== null && $price < 0) {
            $invalid[] = 'HARGA negatif';
        }
        $qty = $invalid ? null : round((float) $prod + (float) $wh, 9);
        $declaredValue = $cols['total'] !== null ? kt_num($g($cols['total'])) : null;
        $rows[] = [
            'row' => (int) $rn, 'code' => $code, 'name' => $name, 'uom' => $uom, 'price' => $price ?? 0.0, 'price_blank' => $priceRaw === '',
            'qty_production' => $prod ?? 0.0, 'qty_warehouse' => $wh ?? 0.0, 'qty' => $qty, 'qty_blank_cells' => ($prodRaw === '' ? 1 : 0) + ($whRaw === '' ? 1 : 0),
            'declared_value' => $declaredValue, 'source_value' => $qty === null ? null : $qty * ($price ?? 0.0), 'invalid' => $invalid,
        ];
    }
    return ['file' => basename($path), 'sha256' => hash_file('sha256', $path), 'header_row' => $headerRow, 'declared_total' => $declared, 'rows' => $rows, 'errors' => $errors,
        'title' => implode(' | ', $titleLines), 'sheets' => kt_sheet_names($path)];
}

/**
 * The ONLY two warehouse mutations the cutover may ever perform. Neither touches is_active: it is a WHERE condition, never an assignment. affected_rows must be exactly 1.
 * (tests/karang_unlock_state_test.php parses these statements and proves the SET column list is exactly [activation_locked].)
 */
const KT_UNLOCK_SQL = 'UPDATE warehouses SET activation_locked = 0 WHERE id = :id AND is_active = 1 AND activation_locked = 1';
const KT_RELOCK_SQL = 'UPDATE warehouses SET activation_locked = 1 WHERE id = :id AND is_active = 1 AND activation_locked = 0';

/** @return list<string> the columns assigned in the SET clause of an UPDATE statement */
function kt_set_columns(string $sql): array
{
    if (preg_match('/\bSET\s+(.*?)\s+WHERE\b/is', $sql, $m) !== 1) {
        return [];
    }
    $cols = [];
    foreach (explode(',', $m[1]) as $part) {
        if (preg_match('/^\s*`?(\w+)`?\s*=/', $part, $c) === 1) {
            $cols[] = strtolower($c[1]);
        }
    }
    return $cols;
}

/** the unlock state machine: READY_TO_UNLOCK (ACTIVE + LOCKED, still gated) · ALREADY_ACTIVE_AND_UNLOCKED (no write) · UNEXPECTED_INACTIVE_STATE (blocked, never activated) · NOT_FOUND */
function kt_unlock_state(?int $isActive, ?int $locked): string
{
    if ($isActive === null || $locked === null) {
        return 'NOT_FOUND';
    }
    if ($isActive !== 1) {
        return 'UNEXPECTED_INACTIVE_STATE';
    }
    return $locked === 1 ? 'READY_TO_UNLOCK' : 'ALREADY_ACTIVE_AND_UNLOCKED';
}

/** the warehouse state in words — "inactive" is never used for an ACTIVE + LOCKED warehouse */
function kt_state_label(?int $isActive, ?int $locked): string
{
    if ($isActive === null || $locked === null) {
        return 'NOT FOUND';
    }
    return ($isActive === 1 ? 'ACTIVE' : 'INACTIVE') . ' + ' . ($locked === 1 ? 'LOCKED' : 'UNLOCKED');
}

/** @return list<string> worksheet names of the workbook (audit metadata) */
function kt_sheet_names(string $path): array
{
    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        return [];
    }
    $x = $z->getFromName('xl/workbook.xml');
    $z->close();
    if ($x === false || ($wb = @simplexml_load_string($x)) === false) {
        return [];
    }
    $names = [];
    foreach ($wb->sheets->sheet ?? [] as $sh) {
        $names[] = (string) $sh['name'];
    }
    return $names;
}

// ============================================================================ plan (read-only)
/** @return array<string,mixed> the master data needed for the mapping — SELECT only */
function kt_master(PDO $pdo, string $warehouseCode, string $effectiveDate): array
{
    $wh = $pdo->prepare('SELECT * FROM warehouses WHERE code = :c');
    $wh->execute(['c' => $warehouseCode]);
    $warehouse = $wh->fetch() ?: null;
    $units = $pdo->query('SELECT id, code, name FROM units')->fetchAll();
    $items = [];
    foreach ($pdo->query('SELECT i.id, i.sku, i.name, i.status, i.base_unit_id, u.code AS base_code, u.name AS base_name FROM items i JOIN units u ON u.id = i.base_unit_id')->fetchAll() as $r) {
        $items[mb_strtoupper(trim((string) $r['sku']), 'UTF-8')][] = $r;
    }
    $conv = [];
    $cs = $pdo->prepare('SELECT item_id, unit_id, conversion_to_base FROM item_unit_conversions WHERE valid_from <= :at AND (valid_to IS NULL OR valid_to > :at2)');
    $cs->execute(['at' => $effectiveDate . ' 00:00:00', 'at2' => $effectiveDate . ' 00:00:00']);
    foreach ($cs->fetchAll() as $r) {
        $conv[(int) $r['item_id']][(int) $r['unit_id']][] = (float) $r['conversion_to_base'];
    }
    return ['warehouse' => $warehouse, 'units' => $units, 'items' => $items, 'conv' => $conv];
}

/** @param list<array<string,mixed>> $units @return list<array<string,mixed>> units whose code OR name equals the (case-normalised) source UoM */
function kt_units_named(array $units, string $uom): array
{
    $n = kt_norm_unit($uom);
    $out = [];
    foreach ($units as $u) {
        if (kt_norm_unit((string) $u['code']) === $n || kt_norm_unit((string) $u['name']) === $n) {
            $out[(int) $u['id']] = $u;
        }
    }
    return array_values($out);
}

/**
 * @param array<string,mixed> $source kt_read_source() result
 * @return array<string,mixed> { rows, summary, blockers, warehouse, state, preview_sha, ... }
 */
function kt_plan(PDO $pdo, array $source, string $warehouseCode = KT_WAREHOUSE_CODE, string $effectiveDate = KT_EFFECTIVE_DATE): array
{
    $m = kt_master($pdo, $warehouseCode, $effectiveDate);
    $rows = [];
    $codeCount = [];
    foreach ($source['rows'] as $r) {
        $k = mb_strtoupper($r['code'], 'UTF-8');
        $codeCount[$k] = ($codeCount[$k] ?? 0) + 1;
    }
    $blockers = [];
    $itemUse = [];   // item_id => list<source code> (two source rows resolving to ONE master item = duplicate mapping)
    foreach ($source['rows'] as $r) {
        $qty = $r['qty'];
        $positive = $qty !== null && $qty > 0;
        $row = [
            'source_row' => $r['row'], 'source_code' => $r['code'], 'source_name' => $r['name'], 'source_uom' => $r['uom'],
            'source_qty_production' => $r['qty_production'], 'source_qty_warehouse' => $r['qty_warehouse'], 'source_qty' => $qty,
            'source_hpp' => $r['price'], 'source_value' => $r['source_value'], 'source_declared_value' => $r['declared_value'],
            'item_id' => null, 'master_sku' => null, 'master_name' => null, 'master_status' => null, 'master_base_unit' => null, 'unit_conversion' => null,
            'norm_qty' => null, 'norm_unit_hpp' => null, 'norm_value' => null, 'value_drift' => null,
            'status' => '', 'severity' => '', 'issue' => '', 'postable' => false,
        ];
        $issues = [];
        $status = null;
        $sev = '';
        if ($r['invalid']) {
            $status = 'QTY_INVALID';
            $sev = 'BLOCKER';
            $issues = $r['invalid'];
        } elseif ($r['code'] === '') {
            $status = 'NOT_FOUND';
            $sev = $positive ? 'BLOCKER' : 'INFO';
            $issues[] = 'kode barang kosong';
        } elseif (($codeCount[mb_strtoupper($r['code'], 'UTF-8')] ?? 0) > 1) {
            $status = 'AMBIGUOUS';
            $sev = $positive ? 'BLOCKER' : 'INFO';
            $issues[] = 'kode barang muncul ' . $codeCount[mb_strtoupper($r['code'], 'UTF-8')] . 'x di file sumber';
        }
        $item = null;
        if ($status === null || $status === 'AMBIGUOUS') {
            $cands = $m['items'][mb_strtoupper($r['code'], 'UTF-8')] ?? [];
            if (count($cands) === 1) {
                $item = $cands[0];
            } elseif (count($cands) > 1 && $status === null) {
                $status = 'AMBIGUOUS';
                $sev = $positive ? 'BLOCKER' : 'INFO';
                $issues[] = 'kode barang cocok dengan ' . count($cands) . ' Master Barang';
            } elseif (count($cands) === 0 && $status === null) {
                $status = 'NOT_FOUND';
                $sev = $positive ? 'BLOCKER' : 'INFO';
                $issues[] = 'kode barang tidak ada di Master Barang (tidak dibuat otomatis)';
            }
        }
        if ($item !== null) {
            $row['item_id'] = (int) $item['id'];
            $row['master_sku'] = $item['sku'];
            $row['master_name'] = $item['name'];
            $row['master_status'] = $item['status'];
            $row['master_base_unit'] = $item['base_code'];
            $itemUse[(int) $item['id']][] = $r['code'];
        }
        // ---- unit + HPP (only when the item is mapped and nothing earlier decided the status)
        $factor = null;
        if ($status === null && $item !== null) {
            if ((string) $item['status'] !== 'ACTIVE' && $positive) {
                $status = 'ITEM_INACTIVE';
                $sev = 'BLOCKER';
                $issues[] = 'Master Barang INACTIVE';
            } elseif ((string) $item['status'] !== 'ACTIVE') {
                $issues[] = 'Master Barang INACTIVE (qty 0 — informasi, tidak memblokir)';
            }
            $named = kt_units_named($m['units'], $r['uom']);
            $baseHit = false;
            foreach ($named as $u) {
                if ((int) $u['id'] === (int) $item['base_unit_id']) {
                    $baseHit = true;
                }
            }
            if ($baseHit || kt_norm_unit($r['uom']) === kt_norm_unit((string) $item['base_code']) || kt_norm_unit($r['uom']) === kt_norm_unit((string) $item['base_name'])) {
                $factor = 1.0;
                $row['unit_conversion'] = 'base unit (×1)';
            } elseif (count($named) === 1) {
                $facts = $m['conv'][(int) $item['id']][(int) $named[0]['id']] ?? [];
                if (count($facts) === 1) {
                    $factor = $facts[0];
                    $row['unit_conversion'] = "1 {$named[0]['code']} = {$facts[0]} {$item['base_code']} (item_unit_conversions)";
                } elseif (count($facts) > 1) {
                    $issues[] = "lebih dari satu konversi aktif {$named[0]['code']}→{$item['base_code']}";
                } else {
                    $issues[] = "tidak ada konversi {$named[0]['code']}→{$item['base_code']} untuk item ini di item_unit_conversions";
                }
            } elseif (count($named) > 1) {
                $issues[] = 'UoM sumber cocok dengan lebih dari satu unit master (' . implode(', ', array_map(static fn ($u) => $u['code'], $named)) . ')';
            } else {
                $issues[] = "UoM sumber \"{$r['uom']}\" tidak ada di master units (dibandingkan tanpa beda huruf besar/kecil)";
            }
            if ($factor === null && $status === null) {
                if ($positive) {
                    $status = 'UNIT_UNRESOLVED';
                    $sev = 'BLOCKER';
                } else {
                    $status = 'ZERO_QTY';
                    $sev = 'INFO';
                    $issues[] = 'unit belum terselesaikan (qty 0 — tidak memblokir)';
                }
            }
        }
        if ($status === null && $item !== null && $factor !== null) {
            if ($positive && $r['price'] <= 0) {
                $status = 'HPP_MISSING';
                $sev = 'BLOCKER';
                $issues[] = $r['price_blank'] ? 'HARGA kosong' : 'HARGA = 0';
            } elseif (!$positive) {
                $status = 'ZERO_QTY';
                $sev = 'INFO';
                $issues[] = 'qty 0 — tidak dibuat FIFO layer';
                if ($r['price'] <= 0) {
                    $issues[] = 'HPP_ZERO (informasi)';
                }
            } else {
                $status = abs($factor - 1.0) < 1e-12 ? 'MATCH_EXACT' : 'MATCH_WITH_UNIT_CONVERSION';
            }
        }
        if ($status === 'ZERO_QTY' && $item === null) {
            $status = 'NOT_FOUND';
        }
        if ($positive && $r['declared_value'] !== null && abs((float) $r['declared_value'] - (float) $r['source_value']) > KT_LINE_TOLERANCE && !in_array($status, ['QTY_INVALID'], true)) {
            $status = 'QTY_INVALID';
            $sev = 'BLOCKER';
            $issues[] = sprintf('nilai sumber tidak valid: kolom Jumlah %.4f ≠ qty × HARGA %.4f', (float) $r['declared_value'], (float) $r['source_value']);
        }
        $row['status'] = $status ?? 'NOT_FOUND';
        $row['severity'] = $sev;
        // ---- normalisation: only for rows that will be posted
        if (in_array($row['status'], ['MATCH_EXACT', 'MATCH_WITH_UNIT_CONVERSION'], true) && $factor !== null && $qty !== null) {
            $nq = round($qty * $factor, 6);
            $nc = round($r['price'] / $factor, 4);
            $row['norm_qty'] = $nq;
            $row['norm_unit_hpp'] = $nc;
            $row['norm_value'] = round($nq * $nc, 4);
            $row['value_drift'] = round($row['norm_value'] - (float) $r['source_value'], 4);
            $row['postable'] = true;
            if (abs($row['value_drift']) > KT_LINE_TOLERANCE) {
                $row['status'] = $row['status'];   // status stays; the value check is its own blocker below
                $issues[] = sprintf('selisih nilai %.4f melebihi toleransi pembulatan %.2f', $row['value_drift'], KT_LINE_TOLERANCE);
                $row['severity'] = 'BLOCKER';
            }
        }
        $row['issue'] = implode('; ', $issues);
        $rows[] = $row;
    }
    // ---- one master item reached by more than one source row = ambiguous mapping
    foreach ($rows as &$row) {
        if ($row['item_id'] !== null && count($itemUse[$row['item_id']] ?? []) > 1 && $row['status'] !== 'AMBIGUOUS') {
            $positive = $row['source_qty'] !== null && $row['source_qty'] > 0;
            $row['issue'] .= ($row['issue'] !== '' ? '; ' : '') . 'Master Barang yang sama dipetakan oleh kode: ' . implode(', ', $itemUse[$row['item_id']]);
            if ($positive) {
                $row['status'] = 'AMBIGUOUS';
                $row['severity'] = 'BLOCKER';
                $row['postable'] = false;
            }
        }
    }
    unset($row);

    // ---- warehouse + global gates
    $wh = $m['warehouse'];
    $state = ['warehouse_found' => $wh !== null, 'warehouse_id' => $wh ? (int) $wh['id'] : null, 'warehouse_code' => $warehouseCode, 'warehouse_name' => $wh['name'] ?? null,
        'warehouse_type' => $wh['warehouse_type'] ?? null, 'is_active' => $wh ? (int) $wh['is_active'] : null, 'activation_locked' => $wh ? (int) $wh['activation_locked'] : null, 'mode' => null,
        'existing_ledger_rows' => 0, 'existing_batches' => 0, 'already_posted' => false, 'already_posted_transactions' => 0, 'period_locked' => false];
    $global = [];
    if ($wh === null) {
        $global[] = "gudang {$warehouseCode} tidak ditemukan di master warehouses (tidak dibuat otomatis)";
    } else {
        if ((int) $wh['is_active'] === 0 && (int) $wh['activation_locked'] === 1) {
            $state['mode'] = 'bypass-inactive-locked';
        } elseif ((int) $wh['is_active'] === 1) {
            $state['mode'] = 'normal-active';
        } else {
            $global[] = 'gudang nonaktif tetapi TIDAK terkunci (is_active=0, activation_locked=0) — status ambigu, posting ditolak';
        }
        $c = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE warehouse_id = :w AND NOT (transaction_type = 'OPENING' AND reference_no = :r)");
        $c->execute(['w' => $wh['id'], 'r' => KT_REFERENCE]);
        $state['existing_ledger_rows'] = (int) $c->fetchColumn();
        $b = $pdo->prepare('SELECT COUNT(*) FROM inventory_batches WHERE warehouse_id = :w');
        $b->execute(['w' => $wh['id']]);
        $state['existing_batches'] = (int) $b->fetchColumn();
        $a = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type = 'OPENING' AND reference_no = :r");
        $a->execute(['r' => KT_REFERENCE]);
        $state['already_posted_transactions'] = (int) $a->fetchColumn();
        $state['already_posted'] = $state['already_posted_transactions'] > 0;
        if ($state['existing_ledger_rows'] > 0 && !$state['already_posted']) {
            $global[] = "gudang sudah punya {$state['existing_ledger_rows']} baris ledger lain — opening balance hanya boleh dimuat ke gudang kosong";
        }
        if ($state['existing_batches'] > 0 && !$state['already_posted']) {
            $global[] = "gudang sudah punya {$state['existing_batches']} FIFO layer — tidak kosong";
        }
    }
    $lock = $pdo->prepare("SELECT COUNT(*) FROM book_closings WHERE status = 'LOCKED' AND :d BETWEEN period_start AND period_end");
    $lock->execute(['d' => $effectiveDate]);
    $state['period_locked'] = (int) $lock->fetchColumn() > 0;
    if ($state['period_locked']) {
        $global[] = "periode {$effectiveDate} sudah dikunci (book_closings LOCKED)";
    }
    if ($source['declared_total'] === null) {
        $global[] = 'total kontrol (Jumlah) tidak ditemukan di file sumber';
    }

    // ---- summary
    $count = ['total' => count($rows)];
    foreach (['MATCH_EXACT', 'MATCH_WITH_UNIT_CONVERSION', 'ZERO_QTY', 'NOT_FOUND', 'AMBIGUOUS', 'UNIT_UNRESOLVED', 'HPP_MISSING', 'QTY_INVALID', 'ITEM_INACTIVE'] as $s) {
        $count[$s] = count(array_filter($rows, static fn ($x) => $x['status'] === $s));
    }
    $count['positive'] = count(array_filter($rows, static fn ($x) => $x['source_qty'] !== null && $x['source_qty'] > 0));
    $count['postable'] = count(array_filter($rows, static fn ($x) => $x['postable'] && $x['severity'] !== 'BLOCKER'));
    $srcQtyByUnit = [];
    $normQtyByUnit = [];
    $srcValue = 0.0;
    $normValue = 0.0;
    $maxDrift = 0.0;
    $declaredSum = 0.0;
    $zeroQtyValue = 0.0;
    foreach ($rows as $x) {
        $u = kt_norm_unit($x['source_uom']);
        if ($x['source_qty'] !== null) {
            $srcQtyByUnit[$u] = ($srcQtyByUnit[$u] ?? 0.0) + $x['source_qty'];
        }
        $srcValue += (float) $x['source_value'];
        $declaredSum += (float) $x['source_declared_value'];
        if ($x['postable']) {
            $normQtyByUnit[kt_norm_unit((string) $x['master_base_unit'])] = ($normQtyByUnit[kt_norm_unit((string) $x['master_base_unit'])] ?? 0.0) + $x['norm_qty'];
            $normValue += $x['norm_value'];
            $maxDrift = max($maxDrift, abs((float) $x['value_drift']));
        }
    }
    ksort($srcQtyByUnit);
    ksort($normQtyByUnit);
    $positiveValue = 0.0;
    foreach ($rows as $x) {
        if ($x['source_qty'] !== null && $x['source_qty'] > 0) {
            $positiveValue += (float) $x['source_value'];
        }
    }
    $summary = [
        'rows' => $count, 'source_file' => $source['file'], 'source_sha256' => $source['sha256'],
        'source_qty_by_unit' => array_map(static fn ($v) => round($v, 6), $srcQtyByUnit),
        'normalized_qty_by_base_unit' => array_map(static fn ($v) => round($v, 6), $normQtyByUnit),
        'source_value' => round($srcValue, 4), 'source_declared_total' => $source['declared_total'], 'source_jumlah_column_sum' => round($declaredSum, 4),
        'source_value_of_positive_rows' => round($positiveValue, 4),
        'normalized_value' => round($normValue, 4), 'difference' => round($normValue - $positiveValue, 4), 'max_line_drift' => round($maxDrift, 4),
    ];
    if (abs($summary['difference']) > KT_TOTAL_TOLERANCE) {
        $global[] = sprintf('selisih nilai sumber vs ternormalisasi %.4f melebihi toleransi total %.2f', $summary['difference'], KT_TOTAL_TOLERANCE);
    }
    if ($source['declared_total'] !== null && abs($srcValue - (float) $source['declared_total']) > 1.0) {
        $global[] = sprintf('Σ(qty × HARGA) %.4f ≠ total kontrol file sumber %.4f', $srcValue, $source['declared_total']);
    }
    foreach ($rows as $x) {
        if ($x['severity'] === 'BLOCKER') {
            $blockers[] = ['source_row' => $x['source_row'], 'source_code' => $x['source_code'], 'source_name' => $x['source_name'], 'status' => $x['status'], 'issue' => $x['issue']];
        }
    }
    foreach ($global as $g) {
        $blockers[] = ['source_row' => null, 'source_code' => '(global)', 'source_name' => '', 'status' => 'GLOBAL', 'issue' => $g];
    }
    $unitConv = [];
    $inactive = [];
    foreach ($rows as $x) {
        if ($x['status'] === 'MATCH_WITH_UNIT_CONVERSION') {
            $unitConv[] = ['source_row' => $x['source_row'], 'source_code' => $x['source_code'], 'source_name' => $x['source_name'], 'source_uom' => $x['source_uom'], 'master_base_unit' => $x['master_base_unit'],
                'unit_conversion' => $x['unit_conversion'], 'source_qty' => $x['source_qty'], 'norm_qty' => $x['norm_qty'], 'source_hpp' => $x['source_hpp'], 'norm_unit_hpp' => $x['norm_unit_hpp'], 'source_value' => $x['source_value'], 'norm_value' => $x['norm_value']];
        }
        if ($x['master_status'] !== null && $x['master_status'] !== 'ACTIVE') {
            $inactive[] = ['source_row' => $x['source_row'], 'source_code' => $x['source_code'], 'master_sku' => $x['master_sku'], 'master_name' => $x['master_name'], 'master_status' => $x['master_status'],
                'source_qty' => $x['source_qty'], 'status' => $x['status'], 'severity' => $x['severity']];
        }
    }
    $byStatus = [];
    foreach ($rows as $x) {
        $byStatus[$x['status']] = ($byStatus[$x['status']] ?? 0) + 1;
    }
    ksort($byStatus);
    $titleWarn = [];
    if (($source['title'] ?? '') !== '' && preg_match('/periode\s+([A-Za-z]+\s+\d{4})/i', (string) $source['title'], $tm) === 1) {
        $titleWarn[] = "judul sheet sumber menyebut \"Periode {$tm[1]}\" — hanya metadata audit; tanggal efektif yang disetujui = {$effectiveDate}";
    }
    $plan = [
        'reference' => KT_REFERENCE, 'effective_date' => $effectiveDate, 'warehouse' => $state, 'rows' => $rows, 'summary' => $summary, 'blockers' => $blockers,
        'blocked' => $blockers !== [], 'by_status' => $byStatus, 'unit_conversions' => $unitConv, 'inactive_items' => $inactive, 'warnings' => $titleWarn,
        'source' => ['file' => $source['file'], 'sha256' => $source['sha256'], 'header_row' => $source['header_row'], 'title' => $source['title'] ?? '', 'sheets' => $source['sheets'] ?? []],
    ];
    $plan['preview_sha'] = kt_preview_sha($plan);
    return $plan;
}

/** SHA256 of exactly what would be posted — the binding between the reviewed preview and the post. */
function kt_preview_sha(array $plan): string
{
    $lines = [];
    foreach ($plan['rows'] as $x) {
        if ($x['postable']) {
            $lines[] = [$x['source_code'], $x['item_id'], number_format((float) $x['norm_qty'], 6, '.', ''), number_format((float) $x['norm_unit_hpp'], 4, '.', '')];
        }
    }
    return hash('sha256', json_encode([$plan['reference'], $plan['effective_date'], $plan['warehouse']['warehouse_id'], $plan['source']['sha256'], $lines], JSON_UNESCAPED_UNICODE));
}

// ============================================================================ output
function kt_fmt(?float $v, int $d = 4): string
{
    return $v === null ? '—' : number_format($v, $d, ',', '.');
}

/** @return list<string> */
function kt_report_lines(array $plan): array
{
    $s = $plan['summary'];
    $c = $s['rows'];
    $w = $plan['warehouse'];
    $o = [];
    $o[] = '=== KARANG TENGAH OPENING BALANCE — PREVIEW (READ ONLY) ===';
    $o[] = "Sumber            : {$s['source_file']}  sha256={$s['source_sha256']}";
    $o[] = '                    sheet: ' . ($plan['source']['sheets'] ? implode(', ', $plan['source']['sheets']) : '—') . '   judul: ' . ($plan['source']['title'] !== '' ? $plan['source']['title'] : '—');
    foreach ($plan['warnings'] as $warn) {
        $o[] = "PERINGATAN        : {$warn}";
    }
    $o[] = "Referensi         : {$plan['reference']}   Effective date: {$plan['effective_date']} 00:00:00   Tipe ledger: OPENING (bukan Pembelian / Stock IN / Transfer / Adjustment)";
    $o[] = sprintf('Gudang            : %s (%s)  id=%s  tipe=%s  is_active=%s  activation_locked=%s  → %s   mode posting=%s', $w['warehouse_name'] ?? '—', $w['warehouse_code'], $w['warehouse_id'] ?? '—', $w['warehouse_type'] ?? '—', $w['is_active'] ?? '—', $w['activation_locked'] ?? '—', kt_state_label($w['is_active'] ?? null, $w['activation_locked'] ?? null), $w['mode'] ?? 'DITOLAK');
    $o[] = sprintf('Ledger gudang    : %d baris lain, %d FIFO layer sebelum posting; sudah diposting dengan referensi ini: %s', $w['existing_ledger_rows'], $w['existing_batches'], $w['already_posted'] ? "YA ({$w['already_posted_transactions']} transaksi)" : 'tidak');
    $o[] = '';
    $o[] = 'RINGKASAN';
    $o[] = sprintf('  total baris sumber            : %d', $c['total']);
    $o[] = sprintf('  qty > 0                       : %d', $c['positive']);
    $o[] = sprintf('  MATCH_EXACT                   : %d', $c['MATCH_EXACT']);
    $o[] = sprintf('  MATCH_WITH_UNIT_CONVERSION    : %d', $c['MATCH_WITH_UNIT_CONVERSION']);
    $o[] = sprintf('  ZERO_QTY                      : %d', $c['ZERO_QTY']);
    $o[] = sprintf('  NOT_FOUND                     : %d', $c['NOT_FOUND']);
    $o[] = sprintf('  AMBIGUOUS                     : %d', $c['AMBIGUOUS']);
    $o[] = sprintf('  UNIT_UNRESOLVED               : %d', $c['UNIT_UNRESOLVED']);
    $o[] = sprintf('  HPP_MISSING                   : %d', $c['HPP_MISSING']);
    $o[] = sprintf('  QTY_INVALID / ITEM_INACTIVE   : %d / %d', $c['QTY_INVALID'], $c['ITEM_INACTIVE']);
    $o[] = sprintf('  FIFO layer yang akan dibuat   : %d', $c['postable']);
    $o[] = '  total qty sumber per satuan   : ' . ($s['source_qty_by_unit'] ? implode(' · ', array_map(static fn ($u, $q) => "{$u} " . kt_fmt($q, 3), array_keys($s['source_qty_by_unit']), $s['source_qty_by_unit'])) : '—');
    $o[] = '  total qty ternormalisasi/base : ' . ($s['normalized_qty_by_base_unit'] ? implode(' · ', array_map(static fn ($u, $q) => "{$u} " . kt_fmt($q, 3), array_keys($s['normalized_qty_by_base_unit']), $s['normalized_qty_by_base_unit'])) : '—');
    $o[] = '  total nilai sumber (qty×HARGA): Rp ' . kt_fmt($s['source_value'], 4) . '   (total kontrol file: ' . ($s['source_declared_total'] === null ? '—' : 'Rp ' . kt_fmt((float) $s['source_declared_total'], 4)) . ')';
    $o[] = '  total nilai ternormalisasi    : Rp ' . kt_fmt($s['normalized_value'], 4);
    $o[] = '  selisih (norm − sumber, qty>0): Rp ' . kt_fmt($s['difference'], 4) . "   selisih terbesar per baris: Rp " . kt_fmt($s['max_line_drift'], 4) . '   (toleransi baris ' . KT_LINE_TOLERANCE . ' / total ' . KT_TOTAL_TOLERANCE . ' — hanya pembulatan 6 dp qty & 4 dp HPP)';
    $o[] = '  baris per status              : ' . implode(' · ', array_map(static fn ($k, $v) => "{$k} {$v}", array_keys($plan['by_status']), $plan['by_status']));
    $o[] = '';
    $o[] = 'DAFTAR KONVERSI SATUAN (' . count($plan['unit_conversions']) . ' baris; hanya dari item_unit_conversions):';
    foreach ($plan['unit_conversions'] as $c) {
        $o[] = sprintf('  baris %d  %s  %s  %s  qty %s → %s %s   HPP %s → %s   nilai %s → %s', $c['source_row'], $c['source_code'], trim($c['source_name']), $c['unit_conversion'], kt_fmt($c['source_qty'], 6), kt_fmt($c['norm_qty'], 6), $c['master_base_unit'], kt_fmt($c['source_hpp'], 6), kt_fmt($c['norm_unit_hpp'], 4), kt_fmt((float) $c['source_value'], 4), kt_fmt($c['norm_value'], 4));
    }
    $o[] = 'MASTER BARANG NONAKTIF (' . count($plan['inactive_items']) . ' baris):';
    foreach ($plan['inactive_items'] as $i) {
        $o[] = sprintf('  baris %d  %s  %s  status master %s  qty %s  → %s%s', $i['source_row'], $i['source_code'], trim((string) $i['master_name']), $i['master_status'], kt_fmt($i['source_qty'], 6), $i['status'], $i['severity'] === 'BLOCKER' ? ' (BLOCKER)' : ' (informasi)');
    }
    $o[] = '';
    $o[] = 'PREVIEW SHA256 : ' . $plan['preview_sha'];
    $o[] = 'STATUS POSTING : ' . ($plan['blocked'] ? 'DIBLOKIR — ' . count($plan['blockers']) . ' blocker' : 'SIAP (tidak ada blocker) — belum ada yang ditulis');
    foreach ($plan['blockers'] as $b) {
        $o[] = sprintf('  BLOCKER  baris %s  %-8s %-26s %s', $b['source_row'] ?? '-', $b['source_code'], $b['status'], $b['issue']);
    }
    $info = array_filter($plan['rows'], static fn ($x) => $x['severity'] === 'INFO' && $x['issue'] !== '');
    $o[] = '';
    $o[] = 'INFORMASI (tidak memblokir): ' . count($info) . ' baris — contoh: ';
    foreach (array_slice(array_values(array_filter($info, static fn ($x) => str_contains($x['issue'], 'HPP_ZERO') || $x['status'] !== 'ZERO_QTY')), 0, 20) as $x) {
        $o[] = sprintf('  baris %d  %s  %s  %s — %s', $x['source_row'], $x['source_code'], trim($x['source_name']), $x['status'], $x['issue']);
    }
    return $o;
}

/** Writes mapping / blockers / summary files into $dir (must be OUTSIDE the application tree — checked by the caller). @return list<string> files */
function kt_write_outputs(array $plan, string $dir): array
{
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("cannot create output directory {$dir}");
    }
    $files = [];
    $cols = ['source_row', 'source_code', 'source_name', 'source_uom', 'source_qty_production', 'source_qty_warehouse', 'source_qty', 'source_hpp', 'source_value', 'item_id', 'master_sku', 'master_name', 'master_status',
        'master_base_unit', 'unit_conversion', 'norm_qty', 'norm_unit_hpp', 'norm_value', 'value_drift', 'status', 'severity', 'issue'];
    $f = fopen($dir . '/karang_mapping_all_rows.csv', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $cols, ',', '"', '');
    foreach ($plan['rows'] as $r) {
        fputcsv($f, array_map(static fn ($c) => $r[$c] ?? '', $cols), ',', '"', '');
    }
    fclose($f);
    $files[] = $dir . '/karang_mapping_all_rows.csv';
    $f = fopen($dir . '/karang_blockers.csv', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['source_row', 'source_code', 'source_name', 'status', 'issue'], ',', '"', '');
    foreach ($plan['blockers'] as $b) {
        fputcsv($f, [$b['source_row'] ?? '', $b['source_code'], $b['source_name'], $b['status'], $b['issue']], ',', '"', '');
    }
    fclose($f);
    $files[] = $dir . '/karang_blockers.csv';
    foreach (['karang_unit_conversions.csv' => ['unit_conversions', ['source_row', 'source_code', 'source_name', 'source_uom', 'master_base_unit', 'unit_conversion', 'source_qty', 'norm_qty', 'source_hpp', 'norm_unit_hpp', 'source_value', 'norm_value']],
        'karang_inactive_items.csv' => ['inactive_items', ['source_row', 'source_code', 'master_sku', 'master_name', 'master_status', 'source_qty', 'status', 'severity']]] as $file => [$key, $c]) {
        $f = fopen($dir . '/' . $file, 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, $c, ',', '"', '');
        foreach ($plan[$key] as $r) {
            fputcsv($f, array_map(static fn ($k) => $r[$k] ?? '', $c), ',', '"', '');
        }
        fclose($f);
        $files[] = $dir . '/' . $file;
    }
    file_put_contents($dir . '/karang_summary.json', json_encode(['reference' => $plan['reference'], 'effective_date' => $plan['effective_date'], 'preview_sha' => $plan['preview_sha'], 'blocked' => $plan['blocked'],
        'warehouse' => $plan['warehouse'], 'summary' => $plan['summary'], 'by_status' => $plan['by_status'], 'warnings' => $plan['warnings'], 'source' => $plan['source'], 'blockers' => $plan['blockers']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $files[] = $dir . '/karang_summary.json';
    return $files;
}

// ============================================================================ post (the only writer)
final class KtOpeningException extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message, public readonly int $exitCode = 11)
    {
        parent::__construct($message);
    }
}

/** @return array<string,mixed> */
function kt_post(PDO $pdo, array $plan, string $actorUsername, string $previewSha): array
{
    if ($plan['blocked']) {
        throw new KtOpeningException('BLOCKED', 'posting refused — ' . count($plan['blockers']) . ' blocker(s); run preview and resolve them first', 11);
    }
    if (!hash_equals($plan['preview_sha'], $previewSha)) {
        throw new KtOpeningException('PREVIEW_MISMATCH', "preview sha256 mismatch: current plan is {$plan['preview_sha']}, you passed {$previewSha} — re-run preview and review it again", 13);
    }
    $actor = $pdo->prepare('SELECT u.id, u.username, u.is_active, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = :u');
    $actor->execute(['u' => $actorUsername]);
    $actor = $actor->fetch();
    if (!$actor || (int) $actor['is_active'] !== 1 || $actor['role_code'] !== 'SUPERADMIN') {
        throw new KtOpeningException('ACTOR_INVALID', "--actor must be an ACTIVE SUPERADMIN user (got '{$actorUsername}')", 14);
    }
    if ($plan['warehouse']['mode'] === 'bypass-inactive-locked') {
        $rm = new ReflectionMethod(FifoService::class, 'postIn');
        $names = array_map(static fn ($p) => $p->getName(), $rm->getParameters());
        if (!in_array('bypassInactiveWarehouseGuard', $names, true)) {
            throw new KtOpeningException('FIFO_SERVICE_OLD', 'the installed FifoService::postIn() has no bypassInactiveWarehouseGuard parameter — cannot load an inactive+locked warehouse', 15);
        }
    }
    $whId = (int) $plan['warehouse']['warehouse_id'];
    $postedAt = date('Y-m-d H:i:s');

    return Database::transaction(function (PDO $tx) use ($plan, $actor, $whId, $postedAt) {
        // idempotency, re-checked INSIDE the transaction (and the per-line transaction_uuid is a second, independent guard)
        $dup = $tx->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE transaction_type = 'OPENING' AND reference_no = :r FOR UPDATE");
        $dup->execute(['r' => KT_REFERENCE]);
        if ((int) $dup->fetchColumn() > 0) {
            throw new KtOpeningException('OPENING_BALANCE_ALREADY_POSTED', 'OPENING_BALANCE_ALREADY_POSTED: ' . KT_REFERENCE . ' has already been posted — nothing was written', 12);
        }
        $itemWasUnlocked = [];
        $posted = [];
        $qtyTotal = 0.0;
        $valueTotal = 0.0;
        foreach ($plan['rows'] as $x) {
            if (!$x['postable']) {
                continue;
            }
            $q = $tx->prepare('SELECT base_unit_id, locked_at FROM items WHERE id = :i');
            $q->execute(['i' => $x['item_id']]);
            $it = $q->fetch();
            if ($it['locked_at'] === null) {
                $itemWasUnlocked[] = (int) $x['item_id'];
            }
            $args = [
                'transaction_uuid' => KT_UUID_PREFIX . $x['source_code'],
                'item_id' => (int) $x['item_id'], 'warehouse_id' => $whId,
                'input_qty' => (float) $x['norm_qty'], 'input_unit_id' => (int) $it['base_unit_id'],
                'unit_price_input' => (float) $x['norm_unit_hpp'],
                'transaction_type' => 'OPENING',
                'transaction_date' => $plan['effective_date'] . ' 00:00:00',
                'reference_no' => KT_REFERENCE,
                'created_by' => (int) $actor['id'], 'username' => $actor['username'],
                'anomaly_approved_by' => (int) $actor['id'],   // an opening baseline is not a purchase price; the price anomaly check does not apply
            ];
            $res = $plan['warehouse']['mode'] === 'bypass-inactive-locked' ? FifoService::postIn($tx, $args, bypassInactiveWarehouseGuard: true) : FifoService::postIn($tx, $args);
            if (!empty($res['idempotent_replay'])) {
                throw new KtOpeningException('OPENING_BALANCE_ALREADY_POSTED', "line {$x['source_code']} already exists (transaction_uuid replay) — nothing was written", 12);
            }
            $posted[] = ['code' => $x['source_code'], 'item_id' => (int) $x['item_id'], 'transaction_id' => (int) $res['transaction_id'], 'batch_id' => (int) $res['batch_id'], 'row' => $x];
            $qtyTotal += (float) $x['norm_qty'];
            $valueTotal += (float) $x['norm_value'];
        }
        // ---- in-transaction verification: a mismatch throws and the whole load rolls back
        $v = kt_verify_ledger($tx, $plan, true);
        foreach ($v as [$name, $ok, $detail]) {
            if (!$ok) {
                throw new KtOpeningException('POST_VERIFY_FAILED', "post-write verification failed ({$name}): {$detail} — rolled back, nothing was committed", 16);
            }
        }
        // ---- audit: one row per source line (original + normalised figures) and one header row
        foreach ($posted as $p) {
            $x = $p['row'];
            AuditService::log($tx, (int) $actor['id'], (string) $actor['username'], 'KARANG_OPENING_LINE', 'inventory_transactions', $p['transaction_id'], null, [
                'source_reference' => KT_REFERENCE, 'source_file' => $plan['source']['file'], 'source_sha256' => $plan['source']['sha256'], 'source_sheet' => $plan['source']['sheets'], 'source_title' => $plan['source']['title'],
                'warehouse_id' => $whId, 'source_row' => $x['source_row'], 'source_code' => $x['source_code'],
                'source_name' => $x['source_name'], 'source_uom' => $x['source_uom'], 'source_qty_production' => $x['source_qty_production'], 'source_qty_warehouse' => $x['source_qty_warehouse'],
                'source_qty' => $x['source_qty'], 'source_hpp' => $x['source_hpp'], 'source_value' => $x['source_value'], 'item_id' => $x['item_id'], 'master_sku' => $x['master_sku'],
                'unit_conversion' => $x['unit_conversion'], 'normalized_qty' => $x['norm_qty'], 'normalized_unit_hpp' => $x['norm_unit_hpp'], 'normalized_value' => $x['norm_value'],
                'value_drift' => $x['value_drift'], 'batch_id' => $p['batch_id'], 'effective_date' => $plan['effective_date'], 'posted_at' => $postedAt, 'posted_by' => $actor['username'],
            ], KT_REFERENCE);
        }
        AuditService::log($tx, (int) $actor['id'], (string) $actor['username'], 'KARANG_OPENING_POST', 'warehouses', $whId, null, [
            'source_reference' => KT_REFERENCE, 'source_file' => $plan['source']['file'], 'source_sha256' => $plan['source']['sha256'], 'source_sheet' => $plan['source']['sheets'], 'source_title' => $plan['source']['title'], 'source_title_warnings' => $plan['warnings'], 'preview_sha256' => $plan['preview_sha'],
            'effective_date' => $plan['effective_date'], 'posted_at' => $postedAt, 'lines' => count($posted), 'normalized_qty_by_base_unit' => $plan['summary']['normalized_qty_by_base_unit'],
            'normalized_value' => round($valueTotal, 4), 'source_value' => $plan['summary']['source_value'], 'items_unlocked_before' => $itemWasUnlocked,
            'transaction_ids' => array_column($posted, 'transaction_id'), 'warehouse_mode' => $plan['warehouse']['mode'],
        ], KT_REFERENCE);
        return ['lines' => count($posted), 'qty_total_mixed_units' => round($qtyTotal, 6), 'value_total' => round($valueTotal, 4), 'posted_at' => $postedAt, 'transaction_ids' => array_column($posted, 'transaction_id')];
    });
}

// ============================================================================ verify (read-only)
/**
 * Ledger-level reconciliation: opening transaction qty == on-hand increment == FIFO layer qty, transaction value == layer value, warehouse total == plan, classification.
 * @return list<array{0:string,1:bool,2:string}>
 */
function kt_verify_ledger(PDO $pdo, array $plan, bool $insideTransaction = false): array
{
    $out = [];
    $whId = (int) $plan['warehouse']['warehouse_id'];
    $eff = $plan['effective_date'] . ' 00:00:00';
    $q = $pdo->prepare(
        "SELECT t.id AS tx, t.transaction_type, t.status, t.transaction_date, t.posting_date, t.reference_no, t.warehouse_id, l.item_id, l.base_qty, l.unit_cost_base, l.subtotal,
                b.id AS batch, b.original_qty_base, b.qty_base AS remaining, b.unit_cost_base AS layer_cost, b.received_date, i.sku
           FROM inventory_transactions t
           JOIN inventory_transaction_lines l ON l.transaction_id = t.id
           LEFT JOIN inventory_batches b ON b.id = l.created_batch_id
           JOIN items i ON i.id = l.item_id
          WHERE t.reference_no = :r AND t.transaction_type = 'OPENING'"
    );
    $q->execute(['r' => KT_REFERENCE]);
    $db = [];
    foreach ($q->fetchAll() as $r) {
        $db[(int) $r['item_id']][] = $r;
    }
    $plannedRows = array_values(array_filter($plan['rows'], static fn ($x) => $x['postable']));
    $out[] = ['one OPENING transaction per positive item', count($db) === count($plannedRows) && array_sum(array_map('count', $db)) === count($plannedRows), sprintf('db=%d plan=%d', array_sum(array_map('count', $db)), count($plannedRows))];
    $bad = [];
    $sumTx = 0.0;
    $sumLayer = 0.0;
    $sumPlan = 0.0;
    foreach ($plannedRows as $x) {
        $r = $db[(int) $x['item_id']][0] ?? null;
        if ($r === null) {
            $bad[] = "{$x['source_code']}: tidak ada transaksi";
            continue;
        }
        $onHand = $pdo->prepare('SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches WHERE item_id = :i AND warehouse_id = :w');
        $onHand->execute(['i' => $x['item_id'], 'w' => $whId]);
        $onHand = (float) $onHand->fetchColumn();
        $planQty = (float) $x['norm_qty'];
        $okRow = $r['status'] === 'POSTED' && (int) $r['warehouse_id'] === $whId && $r['transaction_date'] === $eff
            && abs((float) $r['base_qty'] - $planQty) < 1e-6 && $r['batch'] !== null
            && abs((float) $r['original_qty_base'] - $planQty) < 1e-6 && abs((float) $r['remaining'] - $planQty) < 1e-6 && abs($onHand - $planQty) < 1e-6
            && abs((float) $r['layer_cost'] - (float) $x['norm_unit_hpp']) < 1e-4 && abs((float) $r['unit_cost_base'] - (float) $x['norm_unit_hpp']) < 1e-4
            && substr((string) $r['received_date'], 0, 19) === $eff;
        if (!$okRow) {
            $bad[] = sprintf('%s: tx qty %s / layer %s / sisa %s / on-hand %s / rencana %s', $x['source_code'], $r['base_qty'], $r['original_qty_base'], $r['remaining'], $onHand, $planQty);
        }
        $sumTx += (float) $r['subtotal'];
        $sumLayer += (float) $r['original_qty_base'] * (float) $r['layer_cost'];
        $sumPlan += (float) $x['norm_value'];
    }
    $out[] = ['per item: opening transaction qty == on-hand == FIFO layer qty, cost == plan, dated ' . $eff, $bad === [], $bad ? implode(' | ', array_slice($bad, 0, 5)) : count($plannedRows) . ' item OK'];
    $out[] = ['value: Σ transaction value == Σ FIFO layer value == plan (±' . KT_LINE_TOLERANCE . ')', abs($sumTx - $sumLayer) <= KT_LINE_TOLERANCE && abs($sumLayer - $sumPlan) <= KT_TOTAL_TOLERANCE, sprintf('tx=%.4f layer=%.4f plan=%.4f', $sumTx, $sumLayer, $sumPlan)];
    $wh = $pdo->prepare('SELECT COALESCE(SUM(qty_base * unit_cost_base),0) FROM inventory_batches WHERE warehouse_id = :w');
    $wh->execute(['w' => $whId]);
    $whValue = (float) $wh->fetchColumn();
    $out[] = ['warehouse: Σ item opening value == Karang Tengah inventory value (FIFO layers of the warehouse)', abs($whValue - $sumPlan) <= KT_TOTAL_TOLERANCE, sprintf('warehouse=%.4f plan=%.4f', $whValue, $sumPlan)];
    $vs = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = :r AND transaction_type <> 'OPENING'");
    $vs->execute(['r' => KT_REFERENCE]);
    $out[] = ['classification: nothing with this reference is a Purchase / Stock IN / Transfer / Adjustment', (int) $vs->fetchColumn() === 0, 'only transaction_type=OPENING'];
    if ($insideTransaction) {
        // only meaningful at posting time: later, legitimate movements of a live warehouse must not make `verify` fail
        $tr = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE warehouse_id = :w AND transaction_type <> 'OPENING'");
        $tr->execute(['w' => $whId]);
        $out[] = ['no other movement in the warehouse (no fake transfer / stock in)', (int) $tr->fetchColumn() === 0, 'ledger of the warehouse contains only the opening'];
    }
    return $out;
}

/** Report-level reconciliation (needs MovementReportV3Service): the opening is in the October OPENING, never in Stock IN / Adjustment / Transfer. @return list<array{0:string,1:bool,2:string}> */
function kt_verify_reports(PDO $pdo, array $plan): array
{
    $out = [];
    if (!class_exists('App\\Services\\MovementReportV3Service')) {
        return [['Reports V3 (MovementReportV3Service) is installed', false, 'class not loaded — install Reports V3 first']];
    }
    $whId = (int) $plan['warehouse']['warehouse_id'];
    $total = (float) $plan['summary']['normalized_value'];
    $start = $plan['effective_date'];
    $end = date('Y-m-d', strtotime($start . ' +30 days'));
    $oct = \App\Services\MovementReportV3Service::overview($pdo, $start, $end, $whId);
    $s = $oct['split_totals'] ?? [];
    $out[] = ['Karang Tengah Oct opening == opening balance value', abs((float) ($s['opening'] ?? -1) - $total) <= KT_TOTAL_TOLERANCE, sprintf('opening=%.4f plan=%.4f', $s['opening'] ?? -1, $total)];
    $out[] = ['October Stock IN / Transfer IN / Transfer OUT / OUT / Adjustment of Karang Tengah are all 0', abs((float) ($s['in'] ?? 1)) < 0.005 && abs((float) ($s['tin'] ?? 1)) < 0.005 && abs((float) ($s['tout'] ?? 1)) < 0.005 && abs((float) ($s['out'] ?? 1)) < 0.005 && abs((float) ($s['adjustment'] ?? 1)) < 0.005,
        json_encode(['in' => $s['in'] ?? null, 'tin' => $s['tin'] ?? null, 'tout' => $s['tout'] ?? null, 'out' => $s['out'] ?? null, 'adjustment' => $s['adjustment'] ?? null])];
    $out[] = ['identity opening + IN − OUT + TIN − TOUT + ADJ = closing (difference 0)', abs((float) ($s['difference'] ?? 1)) < 0.01, 'difference=' . ($s['difference'] ?? 'n/a')];
    $sepEnd = date('Y-m-d', strtotime($start . ' -1 day'));
    $sepStart = date('Y-m-01', strtotime($sepEnd));
    $sep = \App\Services\MovementReportV3Service::overview($pdo, $sepStart, $sepEnd, $whId)['split_totals'] ?? [];
    $out[] = ['September: Karang Tengah has no stock and no movement (the opening starts 2026-10-01)', abs((float) ($sep['closing'] ?? 1)) < 0.005 && abs((float) ($sep['in'] ?? 1)) < 0.005, json_encode(['closing' => $sep['closing'] ?? null, 'in' => $sep['in'] ?? null])];
    return $out;
}
