<?php
declare(strict_types=1);

/**
 * SCM OPENING STOCK CORRECTION — "Keterangan 2" of the admin-reviewed reconciliation workbook is the final business truth.
 * Library for scripts/rv3/scm_correction.php.
 *
 *   interpret  workbook only (no database): every Keterangan 2 is parsed into a structured, explainable interpretation
 *   preview    READ ONLY against production: corrected opening dataset, FIFO safety per item, current-stock reconciliation, blockers, ten output files, preview SHA256
 *   post       the only writer (NOT issued yet): ONE transaction, bound to the reviewed preview, idempotent (SCM_OPENING_CORRECTION_ALREADY_POSTED)
 *   verify     read-only post-write reconciliation
 *
 * Source priority per row: Keterangan 2 (highest authority) → otherwise the existing validated SO / opening data (the row is NO_CHANGE; nothing is overwritten from "current stock").
 * The correction is a SEPARATE audited layer: new ADJUSTMENT-type FIFO transactions (StockAdjustmentService — the one place a stock correction is allowed), reference
 * SCM_ADMIN_CORRECTION_20261001, transaction_date = the last instant before the opening (2026-09-30 23:59:59) so every report counts them in September's closing = October's "Stok Awal"
 * and never as an October movement; posting time / created_at are the real posting time. Stock Opname sessions, their inputs, timestamps and audit trail, and every historical
 * transaction are never touched. Interpretation that is not certain is a BLOCKER (never a guess); an approved overrides CSV settles such rows explicitly.
 */

require_once __DIR__ . '/kt_opening_lib.php';
require_once __DIR__ . '/kt_resolve_lib.php';

use App\Services\AuditService;
use App\Services\Database;
use App\Services\InventoryEffectiveDateService;
use App\Services\InventoryHppReportService;
use App\Services\StockAdjustmentService;

const SC_REFERENCE = 'SCM_ADMIN_CORRECTION_20261001';
const SC_EFFECTIVE_DATE = '2026-10-01';
const SC_TX_INSTANT = '2026-09-30 23:59:59';     // the last instant before the 2026-10-01 opening (reports: September closing == October opening)
const SC_OPEN_BOUNDARY = '2026-10-01 00:00:00';
const SC_WAREHOUSE_CODE = 'SCM';
const SC_QTY_TOL = 0.0005;
const SC_VALUE_TOL = 1.0;

final class ScException extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message, public readonly int $exitCode = 11)
    {
        parent::__construct($message);
    }
}

// ============================================================================ workbook
function sc_key(string $name): string
{
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name, 'UTF-8')) ?? '';
}

function sc_f(string $v): float
{
    return is_numeric(trim($v)) ? (float) trim($v) : 0.0;
}

/**
 * @return array{file:string,sha256:string,rows:list<array<string,mixed>>,scm_by_key:array<string,array<string,mixed>>,so_rows:int}
 */
function sc_read_workbook(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("workbook not found: {$path}");
    }
    $grid = \App\Services\XlsxReaderService::readRawGrid($path, 'Perbandingan');
    ksort($grid);
    $hdr = null;
    $col = [];
    $rows = [];
    $want = ['nama barang' => 'name', 'satuan' => 'unit', 'qty so' => 'qty_so', 'qty scm' => 'qty_scm', 'nominal so (rp)' => 'nom_so', 'nominal scm (rp)' => 'nom_scm', 'keterangan' => 'ket', 'key' => 'key', 'satuan scm' => 'scm_unit', 'faktor' => 'factor', 'keterangan 2' => 'ket2'];
    foreach ($grid as $rn => $cells) {
        if ($hdr === null) {
            foreach ($cells as $letter => $v) {
                $h = mb_strtolower(trim((string) $v), 'UTF-8');
                if (isset($want[$h])) {
                    $col[$want[$h]] = $letter;
                }
            }
            foreach (['name', 'unit', 'qty_so', 'qty_scm', 'nom_so', 'nom_scm', 'key', 'ket2'] as $need) {
                if (!isset($col[$need])) {
                    throw new RuntimeException("sheet Perbandingan: column missing for '{$need}'");
                }
            }
            $hdr = (int) $rn;
            continue;
        }
        $g = static fn (string $f): string => isset($col[$f]) ? trim((string) ($cells[$col[$f]] ?? '')) : '';
        if ($g('name') === '' && $g('key') === '') {
            continue;
        }
        $factor = $g('factor') === '' ? 1.0 : sc_f($g('factor'));
        $rows[] = ['row' => (int) $rn, 'name' => $g('name'), 'unit' => $g('unit'), 'qty_so' => sc_f($g('qty_so')), 'qty_scm' => sc_f($g('qty_scm')), 'nom_so' => sc_f($g('nom_so')), 'nom_scm' => sc_f($g('nom_scm')),
            'ket' => $g('ket'), 'key' => $g('key'), 'scm_unit' => $g('scm_unit'), 'factor' => $factor > 0 ? $factor : 1.0, 'ket2' => trim((string) preg_replace('/\s+/u', ' ', $g('ket2')))];
    }
    $scm = [];
    foreach (\App\Services\XlsxReaderService::readRawGrid($path, 'Data SCM') as $rn => $c) {
        if ($rn === 1 || trim((string) ($c['A'] ?? '')) === '' || strtolower(trim((string) $c['A'])) === 'sku') {
            continue;
        }
        $scm[(string) ($c['I'] ?? '')] = ['sku' => trim((string) $c['A']), 'name' => trim((string) ($c['B'] ?? '')), 'unit' => trim((string) ($c['D'] ?? '')), 'stock' => sc_f((string) ($c['E'] ?? '0')), 'value' => sc_f((string) ($c['H'] ?? '0'))];
    }
    return ['file' => basename($path), 'sha256' => hash_file('sha256', $path), 'rows' => $rows, 'scm_by_key' => $scm, 'so_rows' => count($rows)];
}

// ============================================================================ Keterangan 2 interpretation (no database)
/** Indonesian numbers: "73.000" = 73000 (thousands), "15.2" = 15.2 (decimal), "5.520.000" = 5520000. A lone ".ddd" is settled by the workbook's own quantities. @return array{0:float,1:string} */
function sc_number(string $tok, array $refs = []): array
{
    $t = trim($tok, " .,;");
    if ($t === '') {
        return [0.0, 'empty'];
    }
    if (preg_match('/^\d{1,3}(\.\d{3}){2,}$/', $t) === 1) {
        return [(float) str_replace('.', '', $t), 'thousands separators'];
    }
    if (preg_match('/^\d{1,3}\.\d{3}$/', $t) === 1) {
        $asThousand = (float) str_replace('.', '', $t);
        $asDecimal = (float) $t;
        $best = null;
        foreach ($refs as $r) {
            if ($r > 0) {
                foreach ([[$asThousand, 'thousands'], [$asDecimal, 'decimal']] as [$v, $lbl]) {
                    $d = abs(log($v > 0 ? $v / $r : 1e-9));
                    if ($best === null || $d < $best[0]) {
                        $best = [$d, $v, $lbl];
                    }
                }
            }
        }
        if ($best !== null && $best[0] < 0.35) {
            return [$best[1], "ambiguous '{$t}' read as {$best[2]} (closest to the workbook quantity)"];
        }
        return [$asThousand, "'{$t}' read as thousands separator (default; no workbook quantity agrees with a decimal reading)"];
    }
    if (preg_match('/^\d+,\d+$/', $t) === 1) {
        return [(float) str_replace(',', '.', $t), 'decimal comma'];
    }
    return [(float) str_replace(',', '', $t), 'plain'];
}

/** canonical unit word of a text / SO unit */
function sc_unit_canon(?string $u): string
{
    if ($u === null) {
        return '';
    }
    $n = mb_strtoupper(trim($u), 'UTF-8');
    return match (true) {
        in_array($n, ['PCS', 'PC', 'PIECES', 'BIJI', 'BUAH'], true) => 'PCS',
        in_array($n, ['PAK', 'PACK', 'PAH', 'CTN', 'KARTON', 'DUS', 'BOX', 'BAL', 'BALL', 'BAG', 'KARUNG'], true) => 'PACK',
        in_array($n, ['KG', 'KGS', 'KILOGRAM'], true) => 'KG',
        in_array($n, ['GRAM', 'GR', 'G'], true) => 'GR',
        in_array($n, ['LITER', 'LTR', 'L', 'LITRE'], true) => 'LTR',
        $n === 'ML' => 'ML',
        in_array($n, ['SET', 'JAR', 'ROLL', 'PAIL', 'SHEET', 'METER', 'LUSIN', 'BATANG'], true) => $n,
        default => $n,
    };
}

/**
 * @param array<string,mixed> $row workbook row (qty_so, qty_scm, nom_so, nom_scm, unit …)
 * @return array<string,mixed>
 */
function sc_interpret(array $row): array
{
    $raw = (string) $row['ket2'];
    $t = mb_strtolower($raw, 'UTF-8');
    $refs = [(float) $row['qty_so'], (float) $row['qty_scm'], (float) $row['nom_so'], (float) $row['nom_scm']];
    $o = ['raw' => $raw, 'qty' => null, 'qty_unit' => null, 'qty_note' => '', 'nominal' => null, 'price' => null, 'price_unit' => null, 'price_basis' => '', 'alt' => null, 'condition' => null, 'conditions' => [],
        'mapping' => null, 'hpp_remark' => false, 'types' => [], 'flags' => [], 'explanation' => []];
    if ($raw === '') {
        $o['types'] = ['NO_CHANGE'];
        $o['explanation'][] = 'Keterangan 2 kosong → data SO / opening yang sudah divalidasi dipertahankan';
        return $o;
    }
    $num = '(\d+(?:[.,]\d+)*)';
    // ---- nominal correction ("nominal yang betul 120.000"): the QUANTITY stays, the value changes
    $nominalRow = false;
    if (preg_match('/nominal\s+(?:yang|yng|yg)\s+(?:betul|benar)\s+' . $num . '/u', $t, $m) === 1) {
        [$v, $note] = sc_number($m[1], $refs);
        $o['nominal'] = $v;
        $o['qty_note'] = $note;
        $nominalRow = true;
        $o['explanation'][] = 'nominal yang benar Rp ' . number_format($v, 2, ',', '.') . ' (qty tidak berubah)';
    }
    // ---- explicit corrected quantity
    if (!$nominalRow && preg_match('/\b(?:yang|yg)\s+(?:betul|benar)\b(.*)$/u', $t, $m) === 1) {
        $rest = trim($m[1]);
        if (preg_match('/^((?:[a-z\']+\s+){0,5}?)' . $num . '\s*(pcs|pak|pack|ctn|karton|kg|gram|gr|liter|ltr|ml|set|jar|bag|pail|roll)?\b/u', $rest, $q) === 1) {
            $between = trim($q[1]);
            $stop = ['stok', 'stock', 'nya', 'qty', 'jumlah'];
            $words = array_values(array_filter(preg_split('/\s+/u', $between) ?: [], static fn ($w) => $w !== '' && !in_array($w, $stop, true)));
            [$v, $note] = sc_number($q[2], $refs);
            $o['qty'] = $v;
            $o['qty_unit'] = isset($q[3]) && $q[3] !== '' ? $q[3] : null;
            $o['qty_note'] = $note;
            $o['explanation'][] = 'qty final eksplisit ' . rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') . ($o['qty_unit'] ? " {$o['qty_unit']}" : '') . ($note !== 'plain' ? " ({$note})" : '');
            if ($words !== []) {
                $o['flags'][] = 'NAME_WORDS_BEFORE_QTY: ' . implode(' ', $words);
            }
        }
    }
    // ---- price: "harga 600", "harga yang tercantum per pak", "atau sama dengan 550 pak harga 6000"
    if (preg_match('/atau\s+sama\s+dengan\s+' . $num . '\s*(pcs|pak|pack|ctn|karton|kg|gram|gr|liter|ltr|ml)?\s*harga\s+' . $num . '/u', $t, $m) === 1) {
        $o['alt'] = ['qty' => sc_number($m[1], $refs)[0], 'unit' => $m[2] !== '' ? $m[2] : null, 'price' => sc_number($m[3], $refs)[0]];
    }
    $tPrice = preg_replace('/atau\s+sama\s+dengan.*$/u', '', $t) ?? $t;
    if (preg_match('/\bharga\s+(?:yang\s+tercantum\s+)?per\s+(pak|pack|pcs|kg|ctn|karton|set)\b/u', $tPrice, $m) === 1) {
        $o['price_basis'] = 'PER_' . strtoupper($m[1]);
        $o['price_unit'] = $m[1];
        $o['explanation'][] = "harga yang tercantum adalah per {$m[1]} → nilai = qty ({$m[1]}) × harga SO";
    } elseif (preg_match('/\bharga\s+' . $num . '/u', $tPrice, $m) === 1) {
        $o['price'] = sc_number($m[1], $refs)[0];
        $o['price_basis'] = 'EXPLICIT_PRICE';
        $o['explanation'][] = 'harga eksplisit Rp ' . number_format($o['price'], 2, ',', '.') . ' per satuan qty';
    }
    if (preg_match('/di\s+so\s+nominal(?:\s+juga)?|di\s+stok\s+nominal|nominal\s+jg/u', $t) === 1 && $o['nominal'] === null) {
        $o['hpp_remark'] = true;
        $o['explanation'][] = 'merujuk nominal SO ("di so nominal …") — dasar HPP harus dikonfirmasi bila HPP SO ≠ HPP sistem';
    }
    // ---- physical condition
    foreach (['DEADSTOCK' => '/dead\s*stoc?k|deadstok/u', 'EXPIRED' => '/\bexpire[d]?\b|kadaluarsa|kedaluwarsa/u', 'RUSAK' => '/\brusak\b|\breject\b/u'] as $c => $re) {
        if (preg_match($re, $t) === 1) {
            $o['conditions'][] = $c;
        }
    }
    $o['condition'] = $o['conditions'][0] ?? null;
    if ($o['conditions'] !== []) {
        $o['explanation'][] = 'kondisi fisik: ' . implode(', ', $o['conditions']) . ' (qty tetap tercatat sebagai stok fisik; tidak dinolkan kecuali Keterangan 2 menyatakan stok 0)';
    }
    // ---- item mapping
    $map = null;
    foreach ([
        'WRONG_NAME' => '/salah\s+nama\s+(?:harusnya|seharusnya)\s+(.+)$/u',
        'SAME_AS' => '/(?:ini\s+)?sama\s+dengan\s+([^(]+?)\s*(?:\(|$)/u',
        'SYSTEM_HAS_UNDER' => '/(?:di\s*sistem|disistem)\s+(?:masuk\s*nya\s+)?ke\s+(.+)$/u',
        'SO_ENTERED_UNDER' => '/masuk\s+ke\s+(.+?)\s+aja\b/u',
        'ENTERED_UNDER' => '/diinput\s+di\s+(.+?)(?:\s+di\s+so\b|$)/u',
    ] as $kind => $re) {
        if (!str_contains($t, 'atau sama dengan') || $kind !== 'SAME_AS') {
            if (preg_match($re, $t, $m) === 1) {
                $map = ['kind' => $kind, 'target_text' => trim($m[1], " .,"), 'raw' => $m[0]];
                break;
            }
        }
    }
    $o['mapping'] = $map;
    if ($map !== null) {
        $o['explanation'][] = 'koreksi pemetaan item (' . $map['kind'] . '): barang dimaksud = "' . $map['target_text'] . '"';
    }
    // ---- types
    $types = [];
    if ($o['qty'] !== null) {
        $types[] = 'QTY_CORRECTION';
    }
    if ($map !== null) {
        $types[] = 'ITEM_MAPPING_CORRECTION';
    }
    $tu = sc_unit_canon($o['qty_unit']);
    $ru = sc_unit_canon((string) $row['unit']);
    $kc = sc_unit_canon((string) ($row['scm_unit'] ?? ''));
    $physicalSame = $tu !== '' && $kc !== '' && in_array(kr_unit_dim($tu), ['G', 'ML'], true) && kr_unit_dim($tu) === kr_unit_dim($kc);
    if (($o['qty_unit'] !== null && $tu !== $ru) || ($o['qty_unit'] !== null && $kc !== '' && $tu !== $kc && !$physicalSame) || str_contains($o['price_basis'], 'PER_PAK') || $o['alt'] !== null) {
        $types[] = 'UOM_CORRECTION';
    }
    if ($o['nominal'] !== null || $o['price'] !== null || $o['price_basis'] === 'PER_PAK' || str_starts_with($o['price_basis'], 'PER_') || $o['hpp_remark']) {
        $types[] = 'HPP_VALUE_CORRECTION';
    }
    if ($o['conditions'] !== []) {
        $types[] = 'CONDITION_CORRECTION';
    }
    if ($types === []) {
        $types[] = 'NO_CHANGE';
        $o['flags'][] = 'INFORMATIONAL_ONLY';
        $o['explanation'][] = 'teks informatif — tidak ada angka / pemetaan yang dapat ditindaklanjuti';
    }
    if ($o['qty'] === null && $o['conditions'] !== [] && $map === null && $o['nominal'] === null) {
        $o['flags'][] = 'QTY_FROM_SO_FALLBACK';
        $o['explanation'][] = 'qty tidak disebut → qty fisik hasil SO yang sudah divalidasi dipakai (' . rtrim(rtrim(number_format((float) $row['qty_so'], 6, '.', ''), '0'), '.') . ' ' . $row['unit'] . ')';
    }
    $o['types'] = array_values(array_unique($types));
    return $o;
}

// ============================================================================ production state (SELECT only)
function sc_signed_qty_sql(): string
{
    return "CASE WHEN t.transaction_type IN ('IN','OPENING','TRANSFER_IN','PRODUCTION_OUT') THEN l.base_qty WHEN t.transaction_type IN ('OUT','TRANSFER_OUT','PRODUCTION_IN') THEN -ABS(l.base_qty) ELSE l.base_qty END";
}

/** @return array<string,mixed> */
function sc_load_state(PDO $pdo, string $warehouseCode): array
{
    $wh = $pdo->prepare('SELECT * FROM warehouses WHERE code = :c');
    $wh->execute(['c' => $warehouseCode]);
    $w = $wh->fetch();
    if (!$w) {
        throw new ScException('WAREHOUSE_NOT_FOUND', "warehouse {$warehouseCode} not found", 11);
    }
    $whId = (int) $w['id'];
    $items = [];
    $byKey = [];
    $bySku = [];
    foreach ($pdo->query('SELECT i.id, i.sku, i.name, i.status, i.base_unit_id, u.code AS base_code, u.name AS base_name FROM items i JOIN units u ON u.id = i.base_unit_id')->fetchAll() as $r) {
        $r['id'] = (int) $r['id'];
        $items[$r['id']] = $r;
        $bySku[mb_strtoupper(trim((string) $r['sku']), 'UTF-8')][] = $r['id'];
        $byKey[sc_key((string) $r['name'])][] = $r['id'];
    }
    $conv = [];
    $cs = $pdo->prepare('SELECT c.item_id, u.code, u.name, c.conversion_to_base FROM item_unit_conversions c JOIN units u ON u.id = c.unit_id WHERE c.valid_from <= :a AND (c.valid_to IS NULL OR c.valid_to > :b)');
    $cs->execute(['a' => SC_OPEN_BOUNDARY, 'b' => SC_OPEN_BOUNDARY]);
    foreach ($cs->fetchAll() as $c) {
        $conv[(int) $c['item_id']][sc_unit_canon((string) $c['code'])][] = (float) $c['conversion_to_base'];
        $conv[(int) $c['item_id']][sc_unit_canon((string) $c['name'])][] = (float) $c['conversion_to_base'];
    }
    InventoryEffectiveDateService::resetCache();
    $td = InventoryEffectiveDateService::col($pdo, 't');
    $sq = sc_signed_qty_sql();
    $sv = InventoryHppReportService::SIGNED_VALUE_SQL;
    $open = "({$td} < '" . SC_OPEN_BOUNDARY . "' OR (t.transaction_type = 'OPENING' AND {$td} = '" . SC_OPEN_BOUNDARY . "'))";
    $rows = $pdo->prepare("SELECT l.item_id,
            SUM(CASE WHEN {$open} THEN {$sq} ELSE 0 END) AS open_qty, SUM(CASE WHEN {$open} THEN {$sv} ELSE 0 END) AS open_value,
            SUM(CASE WHEN NOT {$open} AND t.transaction_type = 'IN' THEN {$sq} ELSE 0 END) AS m_in, SUM(CASE WHEN NOT {$open} AND t.transaction_type = 'OUT' THEN {$sq} ELSE 0 END) AS m_out,
            SUM(CASE WHEN NOT {$open} AND t.transaction_type = 'TRANSFER_IN' THEN {$sq} ELSE 0 END) AS m_tin, SUM(CASE WHEN NOT {$open} AND t.transaction_type = 'TRANSFER_OUT' THEN {$sq} ELSE 0 END) AS m_tout,
            SUM(CASE WHEN NOT {$open} AND t.transaction_type NOT IN ('IN','OUT','TRANSFER_IN','TRANSFER_OUT') THEN {$sq} ELSE 0 END) AS m_adj,
            SUM(CASE WHEN NOT {$open} THEN {$sv} ELSE 0 END) AS m_value
          FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id
         WHERE t.warehouse_id = :w AND t.status IN ('POSTED','VOID') AND t.inventory_effect = 1 GROUP BY l.item_id");
    $rows->execute(['w' => $whId]);
    $ledger = [];
    foreach ($rows->fetchAll() as $r) {
        $ledger[(int) $r['item_id']] = array_map('floatval', $r);
    }
    $cur = $pdo->prepare('SELECT item_id, COALESCE(SUM(qty_base),0) AS q, COALESCE(SUM(qty_base * unit_cost_base),0) AS v FROM inventory_batches WHERE warehouse_id = :w GROUP BY item_id');
    $cur->execute(['w' => $whId]);
    $current = [];
    foreach ($cur->fetchAll() as $r) {
        $current[(int) $r['item_id']] = ['qty' => (float) $r['q'], 'value' => (float) $r['v']];
    }
    $locked = $pdo->prepare("SELECT COUNT(*) FROM book_closings WHERE status = 'LOCKED' AND :d BETWEEN period_start AND period_end");
    $locked->execute(['d' => SC_TX_INSTANT]);
    $done = $pdo->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = :r');
    $done->execute(['r' => SC_REFERENCE]);
    return ['warehouse' => $w, 'wh_id' => $whId, 'items' => $items, 'by_key' => $byKey, 'by_sku' => $bySku, 'conv' => $conv, 'ledger' => $ledger, 'current' => $current,
        'period_locked' => (int) $locked->fetchColumn() > 0, 'already_posted' => (int) $done->fetchColumn(), 'td' => $td];
}

/** Layers of the given items with the consumption since the opening — the FIFO safety evidence. @return array<int,array<string,mixed>> item_id => facts */
function sc_fifo_facts(PDO $pdo, array $st, array $itemIds): array
{
    if ($itemIds === []) {
        return [];
    }
    $in = implode(',', array_map('intval', $itemIds));
    $tdSrc = InventoryEffectiveDateService::col($pdo, 'ts');
    $layers = $pdo->query("SELECT b.id, b.item_id, b.qty_base, b.original_qty_base, b.unit_cost_base, b.received_date, b.is_negative_layer, COALESCE({$tdSrc}, b.received_date) AS src_date
                             FROM inventory_batches b LEFT JOIN inventory_transaction_lines sl ON sl.id = b.source_transaction_line_id LEFT JOIN inventory_transactions ts ON ts.id = sl.transaction_id
                            WHERE b.warehouse_id = {$st['wh_id']} AND b.item_id IN ({$in}) ORDER BY b.item_id, b.received_date, b.id")->fetchAll();
    $ids = array_map(static fn ($l) => (int) $l['id'], $layers);
    $alloc = [];
    if ($ids !== []) {
        $tdU = InventoryEffectiveDateService::col($pdo, 'tu');
        foreach ($pdo->query('SELECT a.batch_id, a.qty_allocated, a.unit_cost_base, ' . $tdU . ' AS use_date FROM fifo_allocations a JOIN inventory_transaction_lines lu ON lu.id = a.transaction_line_id
                                JOIN inventory_transactions tu ON tu.id = lu.transaction_id WHERE a.batch_id IN (' . implode(',', $ids) . ')')->fetchAll() as $a) {
            $alloc[(int) $a['batch_id']][] = $a;
        }
    }
    $out = [];
    foreach ($itemIds as $i) {
        $out[(int) $i] = ['layers' => [], 'open_qty' => 0.0, 'open_value' => 0.0, 'remaining_open' => 0.0, 'consumed_since_qty' => 0.0, 'consumed_since_cogs' => 0.0, 'remaining_all' => 0.0, 'open_costs' => [], 'negative_layers' => 0];
    }
    foreach ($layers as $l) {
        $i = (int) $l['item_id'];
        $isOpen = (string) $l['src_date'] < SC_OPEN_BOUNDARY;
        $before = 0.0;
        $since = 0.0;
        $cogs = 0.0;
        foreach ($alloc[(int) $l['id']] ?? [] as $a) {
            if ((string) $a['use_date'] < SC_OPEN_BOUNDARY) {
                $before += (float) $a['qty_allocated'];
            } else {
                $since += (float) $a['qty_allocated'];
                $cogs += (float) $a['qty_allocated'] * (float) $a['unit_cost_base'];
            }
        }
        $atOpen = round((float) $l['original_qty_base'] - $before, 6);
        $out[$i]['layers'][] = ['id' => (int) $l['id'], 'qty' => (float) $l['qty_base'], 'cost' => (float) $l['unit_cost_base'], 'received' => $l['received_date'], 'is_open' => $isOpen, 'negative' => (int) $l['is_negative_layer'] === 1, 'since' => $since];
        $out[$i]['remaining_all'] += (float) $l['qty_base'];
        if ((int) $l['is_negative_layer'] === 1) {
            $out[$i]['negative_layers']++;
        }
        if ($isOpen && $atOpen > 0) {
            $out[$i]['open_qty'] += $atOpen;
            $out[$i]['open_value'] += $atOpen * (float) $l['unit_cost_base'];
            $out[$i]['remaining_open'] += (float) $l['qty_base'];
            $out[$i]['consumed_since_qty'] += $since;
            $out[$i]['consumed_since_cogs'] += $cogs;
            $out[$i]['open_costs'][] = round((float) $l['unit_cost_base'], 4);
        }
    }
    return $out;
}

/** simulate the engine's FIFO consumption (received_date, id) of $qty over the current layers → [removed value, qty taken from NON-opening layers] */
function sc_simulate_decrease(array $facts, float $qty): array
{
    $layers = array_values(array_filter($facts['layers'], static fn ($l) => $l['qty'] > 0 && !$l['negative']));
    usort($layers, static fn ($a, $b) => [$a['received'], $a['id']] <=> [$b['received'], $b['id']]);
    $left = $qty;
    $val = 0.0;
    $nonOpen = 0.0;
    foreach ($layers as $l) {
        if ($left <= 0) {
            break;
        }
        $take = min($l['qty'], $left);
        $val += $take * $l['cost'];
        if (!$l['is_open']) {
            $nonOpen += $take;
        }
        $left = round($left - $take, 6);
    }
    return ['value' => round($val, 4), 'non_open' => round($nonOpen, 6), 'short' => max(0.0, $left)];
}

// ============================================================================ overrides (approved settlements of what the text cannot settle)
/**
 * CSV: source_row,field,value,approved_by,note — fields: item_sku · final_qty_base · final_value · hpp_basis(KEEP|SO|SCM) · unit_factor (base units in ONE unit named by the text) · condition · accept_revaluation(YES) · mapping_confirmed(YES)
 * @return array{rows:array<int,array<string,string>>,errors:list<string>,ignored:list<string>,sha256:string,digest:string,file:?string}
 */
function sc_load_overrides(?string $path): array
{
    $res = ['rows' => [], 'errors' => [], 'ignored' => [], 'sha256' => '', 'digest' => '', 'file' => null];
    if ($path === null) {
        return $res;
    }
    if (!is_file($path)) {
        throw new RuntimeException("overrides file not found: {$path}");
    }
    $raw = (string) file_get_contents($path);
    $res['sha256'] = hash('sha256', $raw);
    $res['file'] = basename($path);
    $h = fopen('php://memory', 'w+');
    fwrite($h, preg_replace('/^\xEF\xBB\xBF/', '', $raw));
    rewind($h);
    $hdr = null;
    $n = 0;
    $applied = [];
    while (($f = fgetcsv($h, 0, ',', '"', '')) !== false) {
        $n++;
        if ($f === [null] || (isset($f[0]) && str_starts_with(trim((string) $f[0]), '#'))) {
            continue;
        }
        if ($hdr === null) {
            $hdr = array_map(static fn ($c) => strtolower(trim((string) $c)), $f);
            foreach (['source_row', 'field', 'value', 'approved_by'] as $need) {
                if (!in_array($need, $hdr, true)) {
                    fclose($h);
                    throw new RuntimeException("overrides file: header column missing: {$need}");
                }
            }
            continue;
        }
        $r = [];
        foreach ($hdr as $i => $name) {
            $r[$name] = trim((string) ($f[$i] ?? ''));
        }
        if (implode('', $r) === '') {
            continue;
        }
        $where = "overrides baris {$n} (row {$r['source_row']} {$r['field']})";
        if ($r['approved_by'] === '') {
            $res['ignored'][] = "{$where}: belum disetujui — diabaikan";
            continue;
        }
        $field = strtolower($r['field']);
        if (preg_match('/^[1-9]\d*$/', $r['source_row']) !== 1 || !in_array($field, ['item_sku', 'final_qty_base', 'final_value', 'hpp_basis', 'unit_factor', 'condition', 'accept_revaluation', 'mapping_confirmed'], true) || $r['value'] === '') {
            $res['errors'][] = "{$where}: source_row / field / value tidak valid";
            continue;
        }
        if (in_array($field, ['final_qty_base', 'final_value', 'unit_factor'], true) && (!is_numeric($r['value']) || ($field !== 'final_qty_base' && (float) $r['value'] < 0) || ($field === 'unit_factor' && (float) $r['value'] <= 0))) {
            $res['errors'][] = "{$where}: nilai harus angka" . ($field === 'unit_factor' ? ' > 0' : ' ≥ 0');
            continue;
        }
        if ($field === 'hpp_basis' && !in_array(strtoupper($r['value']), ['KEEP', 'SO', 'SCM'], true)) {
            $res['errors'][] = "{$where}: hpp_basis harus KEEP, SO atau SCM";
            continue;
        }
        if ($field === 'condition' && !in_array(strtoupper($r['value']), ['GOOD', 'DEADSTOCK', 'EXPIRED', 'RUSAK'], true)) {
            $res['errors'][] = "{$where}: condition harus GOOD / DEADSTOCK / EXPIRED / RUSAK";
            continue;
        }
        $row = (int) $r['source_row'];
        if (isset($res['rows'][$row][$field])) {
            $res['errors'][] = "{$where}: field diberikan lebih dari satu kali";
            continue;
        }
        $res['rows'][$row][$field] = in_array($field, ['hpp_basis', 'condition', 'accept_revaluation', 'mapping_confirmed'], true) ? strtoupper($r['value']) : $r['value'];
        $res['rows'][$row]['_approved_by'] = $r['approved_by'];
        if (($r['workbook_name'] ?? '') !== '') {
            $res['rows'][$row]['_expect_name'] = $r['workbook_name'];   // guards against an overrides file used with another workbook
        }
        $applied[] = [$row, $field, $res['rows'][$row][$field], $r['approved_by']];
    }
    fclose($h);
    usort($applied, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $res['digest'] = $applied === [] ? '' : hash('sha256', json_encode($applied));
    return $res;
}

// ============================================================================ plan
function sc_fmt(?float $v, int $d = 4): string
{
    return $v === null ? '' : number_format($v, $d, '.', '');
}

/** master unit conversion for a unit word against an item → factor | null (exactly one active version) */
function sc_item_factor(array $st, int $itemId, string $canonUnit): ?float
{
    $f = array_values(array_unique(array_map(static fn ($x) => round($x, 9), $st['conv'][$itemId][$canonUnit] ?? [])));
    return count($f) === 1 ? $f[0] : null;
}

/** Resolve the production master item of a workbook row. @return array{item:?array<string,mixed>,how:string,candidates:list<array<string,mixed>>} */
function sc_resolve_item(array $row, array $wb, array $st, ?string $overrideSku): array
{
    $pick = static function (array $ids) use ($st): array {
        return array_map(static fn ($i) => $st['items'][$i], $ids);
    };
    if ($overrideSku !== null) {
        $ids = $st['by_sku'][mb_strtoupper(trim($overrideSku), 'UTF-8')] ?? [];
        return count($ids) === 1 ? ['item' => $st['items'][$ids[0]], 'how' => 'OVERRIDE item_sku', 'candidates' => []] : ['item' => null, 'how' => "override item_sku {$overrideSku} tidak ada / ganda di Master Barang", 'candidates' => []];
    }
    $scm = $wb['scm_by_key'][$row['key']] ?? null;
    if ($scm !== null) {
        $ids = $st['by_sku'][mb_strtoupper($scm['sku'], 'UTF-8')] ?? [];
        if (count($ids) === 1) {
            return ['item' => $st['items'][$ids[0]], 'how' => 'Data SCM key → SKU ' . $scm['sku'], 'candidates' => []];
        }
    }
    $ids = $st['by_key'][sc_key((string) $row['name'])] ?? [];
    if (count($ids) === 1) {
        return ['item' => $st['items'][$ids[0]], 'how' => 'nama ternormalisasi = nama Master Barang', 'candidates' => []];
    }
    if (count($ids) > 1) {
        return ['item' => null, 'how' => 'nama ternormalisasi cocok dengan ' . count($ids) . ' Master Barang', 'candidates' => $pick($ids)];
    }
    $cands = [];
    $src = sc_name_tokens((string) $row['name']);
    foreach ($st['items'] as $it) {
        $s = sc_token_score($src, sc_name_tokens((string) $it['name']));
        if ($s >= 0.6) {
            $cands[] = ['id' => $it['id'], 'sku' => $it['sku'], 'name' => $it['name'], 'score' => $s];
        }
    }
    usort($cands, static fn ($a, $b) => $b['score'] <=> $a['score']);
    return ['item' => null, 'how' => 'tidak ada Master Barang dengan nama / SKU yang sama (tidak dibuat otomatis)', 'candidates' => array_slice($cands, 0, 3)];
}

/** @return list<string> */
function sc_name_tokens(string $n): array
{
    $t = preg_split('/[^a-z0-9]+/', mb_strtolower($n, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique($t));
}

function sc_token_score(array $a, array $b): float
{
    $u = count(array_unique(array_merge($a, $b)));
    return $u === 0 ? 0.0 : round(count(array_intersect($a, $b)) / $u, 4);
}

/** the item a mapping phrase points to: exact normalised name, else the ONE item whose tokens contain every token of the phrase. @return array{item:?array<string,mixed>,candidates:list<array<string,mixed>>} */
function sc_resolve_phrase(string $phrase, array $st): array
{
    $ids = $st['by_key'][sc_key($phrase)] ?? [];
    if (count($ids) === 1) {
        return ['item' => $st['items'][$ids[0]], 'candidates' => []];
    }
    $tok = sc_name_tokens($phrase);
    $c = [];
    foreach ($st['items'] as $it) {
        if ($tok !== [] && count(array_diff($tok, sc_name_tokens((string) $it['name']))) === 0) {
            $c[] = ['id' => $it['id'], 'sku' => $it['sku'], 'name' => $it['name']];
        }
    }
    return count($c) === 1 ? ['item' => $st['items'][$c[0]['id']], 'candidates' => []] : ['item' => null, 'candidates' => array_slice($c, 0, 5)];
}

/**
 * @return array<string,mixed>
 */
function sc_plan(PDO $pdo, array $wb, array $ov, string $warehouseCode = SC_WAREHOUSE_CODE): array
{
    $st = sc_load_state($pdo, $warehouseCode);
    $blockers = [];
    $global = [];
    if ($st['period_locked']) {
        $global[] = 'periode 2026-09-30 sudah dikunci (book_closings LOCKED) — koreksi tidak boleh ditulis ke periode terkunci';
    }
    if ($st['already_posted'] > 0) {
        $global[] = 'SCM_OPENING_CORRECTION_ALREADY_POSTED: ' . $st['already_posted'] . ' transaksi dengan referensi ' . SC_REFERENCE . ' sudah ada';
    }
    foreach ($ov['errors'] as $e) {
        $global[] = 'overrides: ' . $e;
    }
    $byRowName = [];
    foreach ($wb['rows'] as $wr) {
        $byRowName[$wr['row']] = $wr['name'];
    }
    foreach ($ov['rows'] as $orow => $o) {
        if (!isset($byRowName[$orow])) {
            $global[] = "overrides: baris workbook {$orow} tidak ada";
        } elseif (isset($o['_expect_name']) && sc_key($o['_expect_name']) !== sc_key($byRowName[$orow])) {
            $global[] = "overrides: baris {$orow} diharapkan \"{$o['_expect_name']}\" tetapi workbook berisi \"{$byRowName[$orow]}\" — file overrides dibuat untuk workbook lain";
        }
    }
    $rows = [];
    $items = [];
    foreach ($wb['rows'] as $r) {
        $o = $ov['rows'][$r['row']] ?? [];
        $res = sc_resolve_item($r, $wb, $st, $o['item_sku'] ?? null);
        $it = $res['item'];
        $row = ['source_row' => $r['row'], 'workbook_name' => $r['name'], 'workbook_unit' => $r['unit'], 'key' => $r['key'], 'ket2' => $r['ket2'], 'item' => $it, 'resolve_how' => $res['how'], 'candidates' => $res['candidates'],
            'qty_so' => $r['qty_so'], 'qty_scm' => $r['qty_scm'], 'nom_so' => $r['nom_so'], 'nom_scm' => $r['nom_scm'], 'factor' => $r['factor'], 'scm_unit' => $r['scm_unit'], 'interp' => sc_interpret($r), 'override' => $o];
        $rows[] = $row;
        if ($it !== null) {
            $items[$it['id']][] = $r['row'];
        }
    }
    $facts = sc_fifo_facts($pdo, $st, array_keys($items));
    $out = [];
    $used = [];
    $ovRows = $ov['rows'];
    foreach ($rows as $row) {
        $r = sc_plan_row($row, $wb, $st, $facts);
        if ($r['item_id'] !== null) {
            $used[$r['item_id']][] = $r['source_row'];
        }
        $out[] = $r;
    }
    // a mapping correction is settled only when a person confirmed it AND the other item of the pair has its own explicit correction row (no duplicated stock)
    $byItem = [];
    foreach ($out as $x) {
        if ($x['item_id'] !== null && $x['ket2'] !== '') {
            $byItem[$x['item_id']][] = $x['source_row'];
        }
    }
    foreach ($out as &$r) {
        $k = array_search('MAPPING_COUNTERPART_REQUIRES_OWN_CORRECTION', $r['blocker_codes'], true);
        if ($k !== false) {
            $other = (int) ($r['mapping']['to_item_id'] === $r['item_id'] ? $r['mapping']['from_item_id'] : $r['mapping']['to_item_id']);
            $confirmed = ($ovRows[$r['source_row']]['mapping_confirmed'] ?? '') === 'YES';
            if ($confirmed && !empty($byItem[$other])) {
                array_splice($r['blockers'], $k, 1);
                array_splice($r['blocker_codes'], $k, 1);
                $r['mapping']['status'] = 'CONFIRMED';
                if ($r['blockers'] === []) {
                    $r['action'] = $r['pending_action'] ?? 'NONE';
                    $r['status'] = $r['action'] === 'NONE' ? 'NO_CHANGE' : 'READY';
                }
            } elseif ($confirmed) {
                $r['blockers'][$k] = 'pasangan pemetaan (' . ($r['mapping']['from_sku'] === $r['master_sku'] ? $r['mapping']['to_sku'] : $r['mapping']['from_sku']) . ') belum punya baris koreksi eksplisit sendiri — tidak boleh ada stok ganda';
            }
        }
    }
    unset($r);
    // two workbook rows reaching ONE master item and both corrected = ambiguous
    foreach ($out as &$r) {
        if ($r['item_id'] !== null && count($used[$r['item_id']]) > 1 && $r['status'] !== 'NO_CHANGE' && !in_array('DUPLICATE_ITEM', $r['blocker_codes'], true)) {
            $r['blocker_codes'][] = 'DUPLICATE_ITEM';
            $r['blockers'][] = 'Master Barang yang sama dicapai oleh baris workbook ' . implode(', ', $used[$r['item_id']]) . ' — pemetaan ganda, tidak boleh digandakan';
            $r['status'] = 'BLOCKED';
        }
    }
    unset($r);
    foreach ($out as $r) {
        foreach ($r['blockers'] as $i => $b) {
            $blockers[] = ['source_row' => $r['source_row'], 'item' => $r['workbook_name'], 'sku' => $r['master_sku'], 'code' => $r['blocker_codes'][$i] ?? 'BLOCKED', 'detail' => $b];
        }
    }
    foreach ($global as $g) {
        $blockers[] = ['source_row' => '', 'item' => '(global)', 'sku' => '', 'code' => 'GLOBAL', 'detail' => $g];
    }
    $sum = sc_summary($out, $st, $wb, $blockers);
    $plan = ['rows' => $out, 'blockers' => $blockers, 'blocked' => $blockers !== [], 'summary' => $sum, 'warehouse' => ['id' => $st['wh_id'], 'code' => $st['warehouse']['code'], 'name' => $st['warehouse']['name']],
        'source' => ['file' => $wb['file'], 'sha256' => $wb['sha256']], 'overrides' => ['file' => $ov['file'], 'sha256' => $ov['sha256'], 'digest' => $ov['digest'], 'ignored' => $ov['ignored'], 'applied_rows' => count($ov['rows'])]];
    $plan['preview_sha'] = sc_preview_sha($plan);
    return $plan;
}

/** @return array<string,mixed> */
function sc_plan_row(array $row, array $wb, array $st, array $facts): array
{
    $it = $row['item'];
    $in = $row['interp'];
    $o = $row['override'];
    $blk = [];
    $codes = [];
    $block = static function (string $code, string $msg) use (&$blk, &$codes): void {
        $codes[] = $code;
        $blk[] = $msg;
    };
    $L = (float) $row['factor'];
    $kUnit = (string) $row['scm_unit'];
    $r = ['source_row' => $row['source_row'], 'workbook_name' => $row['workbook_name'], 'workbook_unit' => $row['workbook_unit'], 'ket2' => $row['ket2'], 'item_id' => $it['id'] ?? null, 'master_sku' => $it['sku'] ?? '', 'item_name' => $it['name'] ?? $row['workbook_name'],
        'base_unit' => $it['base_code'] ?? '', 'resolve_how' => $row['resolve_how'], 'old_qty' => null, 'new_qty' => null, 'delta_qty' => null, 'old_unit_cost' => null, 'new_unit_cost' => null, 'old_value' => null, 'new_value' => null, 'delta_value' => null,
        'condition' => $o['condition'] ?? ($in['condition'] ?? 'GOOD'), 'types' => [], 'parsed_types' => $in['types'], 'mapping' => null, 'status' => 'NO_CHANGE', 'fifo_class' => 'NO_CHANGE', 'blockers' => [], 'blocker_codes' => [], 'hpp_source' => '', 'qty_source' => '',
        'interp' => $in, 'explanation' => $in['explanation'], 'fifo' => null, 'recon' => null, 'action' => 'NONE', 'unit_conversion' => '', 'candidates_hpp' => []];
    $ledger = $it ? ($st['ledger'][$it['id']] ?? null) : null;
    $oldQ = round((float) ($ledger['open_qty'] ?? 0), 6);
    $oldV = round((float) ($ledger['open_value'] ?? 0), 4);
    $r['old_qty'] = $it ? $oldQ : null;
    $r['old_value'] = $it ? $oldV : null;
    $r['old_unit_cost'] = $it && $oldQ > 0 ? round($oldV / $oldQ, 6) : null;
    // current reconciliation for every mapped row
    if ($it) {
        $cur = $st['current'][$it['id']] ?? ['qty' => 0.0, 'value' => 0.0];
        $mv = $ledger ?? ['m_in' => 0, 'm_out' => 0, 'm_tin' => 0, 'm_tout' => 0, 'm_adj' => 0, 'm_value' => 0];
        $r['recon'] = ['in' => (float) $mv['m_in'], 'out' => (float) $mv['m_out'], 'tin' => (float) $mv['m_tin'], 'tout' => (float) $mv['m_tout'], 'adj' => (float) $mv['m_adj'], 'move_qty' => (float) $mv['m_in'] + (float) $mv['m_out'] + (float) $mv['m_tin'] + (float) $mv['m_tout'] + (float) $mv['m_adj'],
            'move_value' => (float) $mv['m_value'], 'actual_qty' => $cur['qty'], 'actual_value' => $cur['value']];
        $expectedWorkbook = $row['qty_scm'] / $L;
        $r['recon']['workbook_scm_base'] = $expectedWorkbook;
    }
    if ($row['ket2'] === '' || $in['types'] === ['NO_CHANGE']) {
        $r['new_qty'] = $r['old_qty'];
        $r['new_value'] = $r['old_value'];
        $r['new_unit_cost'] = $r['old_unit_cost'];
        $r['delta_qty'] = $it ? 0.0 : null;
        $r['delta_value'] = $it ? 0.0 : null;
        $r['status'] = 'NO_CHANGE';
        $r['types'] = ['NO_CHANGE'];
        if ($row['ket2'] !== '' && $it === null) {
            $r['explanation'][] = 'informatif; barang tidak terpetakan ke Master Barang — tidak ada koreksi yang dibutuhkan';
        }
        return $r;
    }
    // ---------------------------------------------------------------- a correcting row
    if ($it === null) {
        $block('ITEM_NOT_IN_MASTER', $row['resolve_how'] . ($row['candidates'] ? ' — kandidat: ' . implode(' | ', array_map(static fn ($c) => "{$c['sku']} {$c['name']}", $row['candidates'])) : '') . ' — beri item_sku lewat overrides atau buat master lewat langkah terpisah');
        $r['status'] = 'BLOCKED';
        $r['blockers'] = $blk;
        $r['blocker_codes'] = $codes;
        $r['types'] = $in['types'];
        return $r;
    }
    $baseCanon = sc_unit_canon((string) $it['base_code']);
    if ($kUnit !== '' && sc_unit_canon($kUnit) !== $baseCanon && sc_unit_canon($kUnit) !== sc_unit_canon((string) $it['base_name'])) {
        $block('BASE_UNIT_MISMATCH', "satuan SCM di workbook ({$kUnit}) ≠ satuan dasar Master Barang ({$it['base_code']})");
    }
    // ---- corrected quantity (base units)
    $newQ = null;
    $qtySource = '';
    $qtyLabel = '';
    if (isset($o['final_qty_base'])) {
        $newQ = round((float) $o['final_qty_base'], 6);
        $qtySource = 'OVERRIDE final_qty_base';
    } elseif ($in['qty'] !== null) {
        $tu = $in['qty_unit'] !== null ? sc_unit_canon($in['qty_unit']) : sc_unit_canon($row['workbook_unit']);
        $N = (float) $in['qty'];
        $factor = null;
        $why = '';
        if (isset($o['unit_factor'])) {
            $factor = (float) $o['unit_factor'];
            $why = 'OVERRIDE unit_factor';
        } elseif ($tu === $baseCanon) {
            $factor = 1.0;
            $why = 'satuan teks = satuan dasar';
        } elseif (($ph = kr_physical_factor($tu === 'GR' ? 'GRAM' : ($tu === 'LTR' ? 'LTR' : $tu), $it['base_code'])) !== null && kr_unit_dim($tu) === kr_unit_dim((string) $it['base_code']) && kr_unit_dim($tu) !== 'PCS') {
            $factor = $ph;
            $why = "skala fisik standar 1 {$tu} = {$ph} {$it['base_code']}";
        } elseif (($mf = sc_item_factor($st, $it['id'], $tu)) !== null) {
            $factor = $mf;
            $why = "item_unit_conversions: 1 {$tu} = {$mf} {$it['base_code']}";
        } else {
            $ratio = ($row['qty_so'] > 0 && $row['qty_scm'] > 0) ? round($row['qty_scm'] / $row['qty_so'], 6) : null;
            $block('UNIT_FACTOR_UNCONFIRMED', "Keterangan 2 menyatakan {$N} " . ($in['qty_unit'] ?? $row['workbook_unit']) . " tetapi satuan dasar master adalah {$it['base_code']} dan tidak ada konversi {$tu}→{$it['base_code']} yang aktif di master" . ($ratio ? " (bukti rasio workbook: Qty SCM / Qty SO = {$ratio} — hanya petunjuk, bukan konfirmasi)" : '') . ' — isi unit_factor lewat overrides setelah konfirmasi bisnis');
        }
        if ($factor !== null) {
            $newQ = round($N * $factor, 6);
            $qtySource = 'KETERANGAN_2 (' . rtrim(rtrim(number_format($N, 6, '.', ''), '0'), '.') . ' ' . ($in['qty_unit'] ?? $row['workbook_unit']) . " × {$factor}; {$why})";
            $r['unit_conversion'] = $why;
        }
        // the workbook's own unit factor must not contradict the physical scale
        if ($factor !== null && ($tu === sc_unit_canon($row['workbook_unit'])) && sc_unit_canon($kUnit) !== '' && kr_unit_dim($kUnit) !== kr_unit_dim($row['workbook_unit']) && !isset($o['unit_factor'])) {
            $block('UNIT_DIMENSION_MISMATCH', "satuan workbook ({$row['workbook_unit']}) dan satuan SCM ({$kUnit}) berdimensi berbeda dengan Faktor {$L} — konversi tidak dapat dipercaya");
        }
    } elseif ($in['qty'] === null && ($in['flags'] && in_array('QTY_FROM_SO_FALLBACK', $in['flags'], true))) {
        $newQ = round($row['qty_so'] / $L, 6);
        $qtySource = 'SO_QTY_FALLBACK (Keterangan 2 tidak menyebut qty; qty fisik hasil SO dipertahankan)';
    } elseif ($in['nominal'] !== null) {
        // only the nominal is corrected: the quantity the workbook has validated (Qty SO = Qty SCM) is authoritative — NOT the ledger opening, which may differ from it
        $agree = $row['qty_so'] > 0 && abs($row['qty_so'] - $row['qty_scm']) <= SC_QTY_TOL * max(1.0, $row['qty_so']);
        if (!$agree) {
            $block('NOMINAL_ONLY_QTY_AMBIGUOUS', sprintf('hanya nominal yang dikoreksi tetapi Qty SO (%s) ≠ Qty SCM (%s) — qty otoritatif tidak jelas; isi final_qty_base lewat overrides', rtrim(rtrim(number_format($row['qty_so'], 6, '.', ''), '0'), '.'), rtrim(rtrim(number_format($row['qty_scm'], 6, '.', ''), '0'), '.')));
        } elseif (kr_unit_dim($row['workbook_unit']) !== '' && kr_unit_dim($kUnit) !== '' && kr_unit_dim($row['workbook_unit']) !== kr_unit_dim($kUnit) && abs($L - 1.0) < 1e-12) {
            $block('UNIT_DIMENSION_MISMATCH', "satuan workbook ({$row['workbook_unit']}) dan satuan SCM ({$kUnit}) berdimensi berbeda dengan Faktor 1 — qty {$row['qty_so']} tidak dapat dipastikan dalam {$it['base_code']}; isi final_qty_base lewat overrides yang disetujui admin");
        } else {
            $newQ = round($row['qty_so'] / $L, 6);
            $qtySource = 'WORKBOOK_QTY (Qty SO = Qty SCM = qty fisik tervalidasi; hanya nominal yang dikoreksi)';
        }
    } elseif ($in['mapping'] !== null) {
        $block('MAPPING_TARGET_QTY_UNSPECIFIED', 'Keterangan 2 mengoreksi pemetaan item tetapi tidak menyebut qty final — isi final_qty_base untuk item tujuan lewat overrides');
    } else {
        $block('UNPARSED_TEXT', 'teks Keterangan 2 tidak dapat diterjemahkan menjadi koreksi yang pasti');
    }
    // ---- mapping: from / to + counterpart
    if ($in['mapping'] !== null) {
        $m = $in['mapping'];
        $tgt = sc_resolve_phrase($m['target_text'], $st);
        $r['mapping'] = ['kind' => $m['kind'], 'phrase' => $m['target_text'], 'from_item_id' => null, 'to_item_id' => null, 'from_sku' => '', 'to_sku' => '', 'status' => 'UNRESOLVED', 'candidates' => $tgt['candidates']];
        if ($tgt['item'] !== null) {
            $t = $tgt['item'];
            $self = (int) $t['id'] === (int) $it['id'];
            $r['mapping']['status'] = $self ? 'SAME_ITEM' : 'RESOLVED';
            $isCounterpartWrong = in_array($m['kind'], ['SYSTEM_HAS_UNDER'], true);   // "di sistem ke X": the system booked THIS physical stock under X
            if (!$self) {
                $r['mapping']['from_item_id'] = $isCounterpartWrong ? $t['id'] : $it['id'];
                $r['mapping']['to_item_id'] = $isCounterpartWrong ? $it['id'] : $t['id'];
                $r['mapping']['from_sku'] = $isCounterpartWrong ? $t['sku'] : $it['sku'];
                $r['mapping']['to_sku'] = $isCounterpartWrong ? $it['sku'] : $t['sku'];
            }
            if (!$self) {
                $block('MAPPING_COUNTERPART_REQUIRES_OWN_CORRECTION', "pemetaan {$m['kind']}: stok berpindah antara {$it['sku']} dan {$t['sku']} — setiap item harus punya koreksi final eksplisit sendiri (baris workbook atau overrides final_qty_base) agar tidak ada stok ganda; konfirmasi dengan overrides");
            }
        } else {
            $block('MAPPING_TARGET_UNRESOLVED', "barang \"{$m['target_text']}\" pada Keterangan 2 tidak dapat dipastikan di Master Barang" . ($tgt['candidates'] ? ' — kandidat: ' . implode(' | ', array_map(static fn ($c) => "{$c['sku']} {$c['name']}", $tgt['candidates'])) : ' — tidak ditemukan') . ' — beri item_sku / koreksi lewat overrides');
        }
    }
    // ---- corrected value / unit cost
    $newV = null;
    $valueFromFifo = false;
    $hpp = '';
    $soCostBase = ($row['qty_so'] > 0 && $row['nom_so'] > 0) ? $row['nom_so'] / ($row['qty_so'] / $L) : null;
    $scmCostBase = ($row['qty_scm'] > 0 && $row['nom_scm'] > 0) ? $row['nom_scm'] / ($row['qty_scm'] / $L) : null;
    $keepCost = $oldQ > 0 && $oldV > 0 ? $oldV / $oldQ : null;
    $r['candidates_hpp'] = ['keep_system' => $keepCost, 'so_price_base' => $soCostBase, 'scm_export_cost_base' => $scmCostBase];
    if ($newQ !== null) {
        if (abs($newQ) < 1e-9) {
            $newV = 0.0;
            $hpp = 'ZERO_QTY';
        } elseif (isset($o['final_value'])) {
            $newV = round((float) $o['final_value'], 4);
            $hpp = 'OVERRIDE final_value';
        } elseif ($in['nominal'] !== null) {
            $newV = round((float) $in['nominal'], 4);
            $hpp = 'KETERANGAN_2 nominal';
        } elseif ($in['price'] !== null && $in['price_basis'] === 'EXPLICIT_PRICE') {
            $newV = round($newQ * (float) $in['price'], 4);
            $hpp = 'KETERANGAN_2 harga ' . rtrim(rtrim(number_format((float) $in['price'], 4, '.', ''), '0'), '.') . ' per ' . ($in['qty_unit'] ?? $row['workbook_unit']);
            if ($in['alt'] !== null) {
                $altV = round((float) $in['alt']['qty'] * (float) $in['alt']['price'], 4);
                if (abs($altV - $newV) > SC_VALUE_TOL) {
                    $block('PRICE_ALTERNATIVE_INCONSISTENT', "Keterangan 2 memberi dua bentuk harga yang tidak sama nilainya: {$newV} vs {$altV}");
                }
            }
        } elseif (str_starts_with($in['price_basis'], 'PER_')) {
            $priceUnit = sc_unit_canon((string) $in['price_unit']);
            $soPrice = $row['qty_so'] > 0 ? $row['nom_so'] / $row['qty_so'] : null;
            $N = $in['qty'];
            if ($soPrice === null || $N === null || $priceUnit !== sc_unit_canon($in['qty_unit'] ?? $row['workbook_unit']) && $priceUnit !== sc_unit_canon($row['workbook_unit'])) {
                $block('HPP_BASIS_UNCLEAR', 'harga per satuan disebut tetapi qty / harga SO tidak cukup untuk menghitung nilai');
            } else {
                $newV = round((float) $N * $soPrice, 4);
                $hpp = 'KETERANGAN_2 harga per ' . $in['price_unit'] . ' = harga SO ' . rtrim(rtrim(number_format($soPrice, 4, '.', ''), '0'), '.') . ' × qty teks';
            }
        } else {
            $basis = $o['hpp_basis'] ?? null;
            $cost = null;
            if ($basis === 'SO') {
                $cost = $soCostBase;
                $hpp = 'OVERRIDE hpp_basis SO';
            } elseif ($basis === 'SCM') {
                $cost = $scmCostBase;
                $hpp = 'OVERRIDE hpp_basis SCM';
            } elseif ($basis === 'KEEP' || ($basis === null && $keepCost !== null)) {
                $cost = $keepCost;
                $hpp = $basis === 'KEEP' ? 'OVERRIDE hpp_basis KEEP' : 'KEEP_SYSTEM_COST (HPP opening yang ada dipertahankan)';
            } elseif ($basis === null && $soCostBase !== null) {
                $cost = $soCostBase;
                $hpp = 'SO_PRICE (belum ada layer opening; HPP dari harga SO)';
            } elseif ($basis === null && $scmCostBase !== null) {
                $cost = $scmCostBase;
                $hpp = 'SCM_EXPORT_COST (belum ada layer opening; HPP dari workbook SCM)';
            }
            if ($cost === null || $cost <= 0) {
                $block('HPP_MISSING', 'tidak ada HPP yang dapat dipakai (tidak ada layer opening, harga SO, maupun biaya SCM) — isi final_value atau hpp_basis lewat overrides');
            } else {
                // a remark about the SO nominal while the SO price and the system cost disagree = the basis must be confirmed, not assumed
                if ($in['hpp_remark'] && $basis === null && $keepCost !== null && $soCostBase !== null && abs($soCostBase - $keepCost) / $keepCost > 0.005) {
                    $block('HPP_BASIS_CONFIRMATION', sprintf('Keterangan 2 merujuk nominal SO, tetapi HPP SO %.4f ≠ HPP sistem %.4f per %s (selisih %.1f%%) — pilih hpp_basis KEEP / SO / SCM atau final_value lewat overrides', $soCostBase, $keepCost, $it['base_code'], abs($soCostBase - $keepCost) / $keepCost * 100));
                }
                if ($newQ < $oldQ && ($basis === null || $basis === 'KEEP') && $keepCost !== null) {
                    $newV = null;   // decided by the FIFO walk below (exact removed layer value)
                    $valueFromFifo = true;
                } else {
                    $newV = round($newQ * $cost, 4);
                }
            }
        }
    }
    // ---- FIFO safety
    $f = $facts[$it['id']] ?? null;
    $delta = $newQ !== null ? round($newQ - $oldQ, 6) : null;
    $fifoClass = 'BLOCKED';
    $fifoNote = '';
    if ($f !== null && $newQ !== null) {
        if (abs($f['open_qty'] - $oldQ) > 0.001 && ($oldQ > 0 || $f['open_qty'] > 0)) {
            $r['explanation'][] = sprintf('PERHATIAN: qty opening ledger %.6f ≠ Σ layer opening %.6f', $oldQ, $f['open_qty']);
        }
        if ($f['negative_layers'] > 0) {
            $block('NEGATIVE_FIFO_LAYER', 'item punya layer FIFO negatif — koreksi ditahan sampai layer negatif diselesaikan');
        }
        if ($delta < 0) {
            $sim = sc_simulate_decrease($f, abs($delta));
            if ($sim['short'] > 0 || $sim['non_open'] > 0) {
                $block('REDUCTION_EXCEEDS_OPENING_LAYER', sprintf('pengurangan %.6f melebihi sisa layer opening (%.6f) atau akan mengonsumsi layer sesudah opening (%.6f) — DIBLOKIR; layer yang sudah terpakai tidak diedit', abs($delta), $f['remaining_open'], $sim['non_open'] + $sim['short']));
            } elseif ($newV === null) {
                $newV = round($oldV - $sim['value'], 4);
                $hpp = $hpp !== '' ? $hpp : 'KEEP_SYSTEM_COST (nilai = sisa layer FIFO)';
            }
        }
        if ($newV !== null) {
            $newCost = $newQ > 0 ? $newV / $newQ : null;
            $costDiffers = !$valueFromFifo && $oldQ > 0 && $newQ > 0 && $keepCost !== null && $newCost !== null && abs($newCost - $keepCost) > max(0.01, 0.0005 * $keepCost);
            $fifo = ['open_qty' => $f['open_qty'], 'consumed_since_qty' => $f['consumed_since_qty'], 'remaining_open' => $f['remaining_open'], 'old_unit_cost' => $keepCost, 'new_unit_cost' => $newCost, 'cogs_consumed' => $f['consumed_since_cogs'], 'cost_differs' => $costDiffers];
            if ($newQ < -1e-9) {
                $block('NEGATIVE_FINAL_QTY', 'qty final negatif');
            }
            if ($costDiffers) {
                $mixed = count(array_unique($f['open_costs'])) > 1;
                $fifo['cogs_restatement'] = round($f['consumed_since_qty'] * ($newCost - $keepCost), 4);
                if ($f['consumed_since_qty'] > 1e-9) {
                    $fifoClass = 'REQUIRES_COGS_RESTATEMENT';
                    $block('REQUIRES_COGS_RESTATEMENT', sprintf('HPP koreksi %.4f ≠ HPP opening %.4f dan %.6f dari layer opening sudah terpakai sejak 1 Okt (COGS terkonsumsi Rp %s) — dampak restatement Rp %s; layer terpakai tidak diedit diam-diam, butuh penanganan eksplisit', $newCost, $keepCost, $f['consumed_since_qty'], number_format($f['consumed_since_cogs'], 2, ',', '.'), number_format($fifo['cogs_restatement'], 2, ',', '.')));
                } elseif ($mixed && ($o['accept_revaluation'] ?? '') !== 'YES') {
                    $fifoClass = 'REQUIRES_FIFO_REVALUATION';
                    $block('REQUIRES_FIFO_REVALUATION', 'layer opening item ini memiliki beberapa HPP berbeda — revaluasi menggantikan seluruh layer; setujui dengan overrides accept_revaluation=YES');
                } else {
                    $simAll = sc_simulate_decrease($f, $oldQ);
                    if ($simAll['short'] > 0 || $simAll['non_open'] > 0) {
                        $fifoClass = 'REQUIRES_FIFO_REVALUATION';
                        $block('REVALUATION_WOULD_CONSUME_POST_OPENING_LAYER', 'revaluasi harus mengganti seluruh layer opening, tetapi urutan FIFO akan mengonsumsi layer sesudah opening lebih dulu — butuh penanganan eksplisit');
                    } else {
                        $fifoClass = 'SAFE_VALUE_CORRECTION';
                        $r['action'] = 'REVALUE_PAIR';
                    }
                }
            } elseif (abs($delta ?? 0) > 1e-9) {
                $fifoClass = 'SAFE_QTY_CORRECTION';
                $r['action'] = $delta > 0 ? 'INCREASE' : 'DECREASE';
            } else {
                $fifoClass = 'NO_CHANGE';
            }
            $r['fifo'] = $fifo;
        }
    }
    $r['new_qty'] = $newQ;
    $r['new_value'] = $newV;
    $r['new_unit_cost'] = $newQ !== null && $newV !== null ? ($newQ > 0 ? round($newV / $newQ, 6) : $r['old_unit_cost']) : null;
    $r['delta_qty'] = $delta;
    $r['delta_value'] = $newV !== null ? round($newV - $oldV, 4) : null;
    $r['hpp_source'] = $hpp;
    $r['qty_source'] = $qtySource;
    $r['fifo_class'] = $blk !== [] && $fifoClass === 'NO_CHANGE' ? 'BLOCKED' : ($blk !== [] ? $fifoClass : $fifoClass);
    // effective correction types: a stated quantity that already equals the opening is NOT a quantity correction
    $types = [];
    if ($in['qty'] !== null || isset($o['final_qty_base']) || in_array('QTY_FROM_SO_FALLBACK', $in['flags'], true) || ($in['nominal'] !== null && $newQ !== null)) {
        if ($delta === null || abs($delta) > SC_QTY_TOL) {
            $types[] = 'QTY_CORRECTION';
        }
    }
    if ($in['mapping'] !== null) {
        $types[] = 'ITEM_MAPPING_CORRECTION';
    }
    if (in_array('UOM_CORRECTION', $in['types'], true)) {
        $types[] = 'UOM_CORRECTION';
    }
    // an HPP correction exists only when the corrected unit cost really differs from the opening unit cost (a stated nominal that equals qty × the existing HPP is NOT an HPP correction)
    if (($r['fifo']['cost_differs'] ?? false) || (isset($o['final_value']) && $oldQ <= 0)) {
        $types[] = 'HPP_VALUE_CORRECTION';
    }
    if ($in['conditions'] !== [] || isset($o['condition'])) {
        $types[] = 'CONDITION_CORRECTION';
    }
    $r['types'] = $types === [] ? ['NO_CHANGE'] : array_values(array_unique($types));
    $r['blockers'] = $blk;
    $r['blocker_codes'] = $codes;
    $r['pending_action'] = $r['action'];
    if ($blk !== []) {
        $r['status'] = 'BLOCKED';
        $r['action'] = 'NONE';
    } elseif ($r['action'] === 'NONE') {
        $r['status'] = 'NO_CHANGE';
        $r['fifo_class'] = 'NO_CHANGE';
    } else {
        $r['status'] = 'READY';
    }
    return $r;
}

/** @return array<string,mixed> */
function sc_summary(array $rows, array $st, array $wb, array $blockers): array
{
    $cnt = static fn (callable $f): int => count(array_filter($rows, $f));
    $has = static fn (string $t) => static fn ($r) => in_array($t, $r['types'], true);
    $withKet = array_filter($rows, static fn ($r) => $r['ket2'] !== '');
    $dQ = 0.0;
    $dV = 0.0;
    foreach ($rows as $r) {
        if ($r['status'] !== 'BLOCKED' && $r['delta_value'] !== null) {
            $dV += $r['delta_value'];
        }
    }
    $oldTotal = 0.0;
    foreach ($st['ledger'] as $l) {
        $oldTotal += $l['open_value'];
    }
    $curVal = 0.0;
    foreach ($st['current'] as $c) {
        $curVal += $c['value'];
    }
    $unexplQ = 0.0;
    foreach ($rows as $r) {
        if ($r['recon'] !== null) {
            $unexplQ += abs($r['recon']['actual_qty'] - ($r['old_qty'] + $r['recon']['move_qty']));
        }
    }
    $blockedRows = array_filter($rows, static fn ($r) => $r['status'] === 'BLOCKED');
    return [
        'rows_in_workbook' => count($rows), 'rows_with_keterangan2' => count($withKet),
        'qty_corrections' => $cnt($has('QTY_CORRECTION')), 'mapping_corrections' => $cnt($has('ITEM_MAPPING_CORRECTION')), 'uom_corrections' => $cnt($has('UOM_CORRECTION')), 'hpp_corrections' => $cnt($has('HPP_VALUE_CORRECTION')),
        'condition_corrections' => $cnt($has('CONDITION_CORRECTION')), 'deadstock' => $cnt(static fn ($r) => in_array('CONDITION_CORRECTION', $r['types'], true) && $r['condition'] === 'DEADSTOCK'),
        'expired' => $cnt(static fn ($r) => in_array('CONDITION_CORRECTION', $r['types'], true) && $r['condition'] === 'EXPIRED'), 'rusak' => $cnt(static fn ($r) => in_array('CONDITION_CORRECTION', $r['types'], true) && $r['condition'] === 'RUSAK'),
        'no_change_rows' => $cnt(static fn ($r) => $r['status'] === 'NO_CHANGE'), 'ready_rows' => $cnt(static fn ($r) => $r['status'] === 'READY'), 'blocked_rows' => count($blockedRows), 'blockers' => count($blockers),
        'fifo_classes' => array_count_values(array_map(static fn ($r) => $r['fifo_class'], array_filter($rows, static fn ($r) => $r['ket2'] !== ''))),
        'old_scm_opening_value_all_items' => round($oldTotal, 4), 'delta_value_of_non_blocked_corrections' => round($dV, 4), 'corrected_scm_opening_value' => round($oldTotal + $dV, 4),
        'delta_value_of_blocked_corrections_not_included' => round(array_sum(array_map(static fn ($r) => (float) ($r['delta_value'] ?? 0), $blockedRows)), 4),
        'current_system_inventory_value' => round($curVal, 4), 'expected_current_inventory_value_after_correction' => round($curVal + $dV, 4),
        'remaining_difference_after_correction_value' => 0.0, 'unexplained_qty_abs_sum (actual − (old opening + movements))' => round($unexplQ, 6),
        'effective_date' => SC_EFFECTIVE_DATE, 'transaction_instant' => SC_TX_INSTANT, 'reference' => SC_REFERENCE,
    ];
}

function sc_preview_sha(array $plan): string
{
    $lines = [];
    foreach ($plan['rows'] as $r) {
        if ($r['status'] === 'READY') {
            $lines[] = [$r['source_row'], $r['item_id'], $r['action'], sc_fmt($r['new_qty'], 6), sc_fmt($r['new_value'], 4), $r['condition']];
        }
    }
    return hash('sha256', json_encode([SC_REFERENCE, SC_EFFECTIVE_DATE, $plan['warehouse']['id'], $plan['source']['sha256'], $plan['overrides']['digest'], $lines]));
}

// ============================================================================ outputs
/** @return list<string> */
function sc_report_lines(array $plan): array
{
    $s = $plan['summary'];
    $o = ['=== SCM OPENING CORRECTION — PREVIEW (READ ONLY; nothing posted, no FIFO layer touched) ===', "Sumber : {$plan['source']['file']}  sha256={$plan['source']['sha256']}  sheet Perbandingan, kolom Keterangan 2 = otoritas bisnis tertinggi",
        "Referensi: " . SC_REFERENCE . "   efektif " . SC_EFFECTIVE_DATE . "  (transaksi bertanggal " . SC_TX_INSTANT . " → masuk Stok Akhir September = Stok Awal Oktober; BUKAN gerakan Oktober)", ''];
    $o[] = sprintf('baris workbook %d · baris dengan Keterangan 2 %d · NO_CHANGE %d · SIAP %d · DIBLOKIR %d (%d blocker)', $s['rows_in_workbook'], $s['rows_with_keterangan2'], $s['no_change_rows'], $s['ready_rows'], $s['blocked_rows'], $s['blockers']);
    $o[] = sprintf('koreksi: qty %d · pemetaan item %d · UoM %d · HPP/nilai %d · kondisi %d (deadstock %d · expired %d · rusak %d)', $s['qty_corrections'], $s['mapping_corrections'], $s['uom_corrections'], $s['hpp_corrections'], $s['condition_corrections'], $s['deadstock'], $s['expired'], $s['rusak']);
    $o[] = 'klasifikasi FIFO: ' . ($s['fifo_classes'] ? implode(' · ', array_map(static fn ($k, $v) => "{$k} {$v}", array_keys($s['fifo_classes']), $s['fifo_classes'])) : '—');
    $o[] = 'Stok Awal SCM lama (semua item, ledger)     : Rp ' . number_format($s['old_scm_opening_value_all_items'], 2, ',', '.');
    $o[] = 'Δ koreksi yang tidak diblokir                : Rp ' . number_format($s['delta_value_of_non_blocked_corrections'], 2, ',', '.') . '   (Δ baris diblokir, belum termasuk: Rp ' . number_format($s['delta_value_of_blocked_corrections_not_included'], 2, ',', '.') . ')';
    $o[] = 'Stok Awal SCM terkoreksi                     : Rp ' . number_format($s['corrected_scm_opening_value'], 2, ',', '.');
    $o[] = 'persediaan SCM saat ini (sistem, FIFO)       : Rp ' . number_format($s['current_system_inventory_value'], 2, ',', '.') . '   diharapkan setelah koreksi: Rp ' . number_format($s['expected_current_inventory_value_after_correction'], 2, ',', '.');
    $o[] = '';
    $o[] = 'KOREKSI PER BARIS:';
    foreach ($plan['rows'] as $r) {
        if ($r['ket2'] === '') {
            continue;
        }
        $o[] = sprintf('  baris %-4d %-44s %-9s %-26s qty %s → %s  nilai %s → %s  [%s]', $r['source_row'], mb_substr(trim((string) $r['workbook_name']), 0, 44), $r['status'], implode('+', $r['types']), sc_fmt($r['old_qty'], 3), sc_fmt($r['new_qty'], 3), sc_fmt($r['old_value'], 2), sc_fmt($r['new_value'], 2), $r['fifo_class']);
        $o[] = '            Ket2: "' . $r['ket2'] . '"';
        foreach ($r['blockers'] as $b) {
            $o[] = '            BLOCKER: ' . $b;
        }
    }
    $o[] = '';
    $o[] = 'PREVIEW SHA256 : ' . $plan['preview_sha'];
    $o[] = 'STATUS POSTING : ' . ($plan['blocked'] ? 'DIBLOKIR — ' . count($plan['blockers']) . ' blocker (belum ada yang ditulis)' : 'SIAP — ' . $plan['summary']['ready_rows'] . ' koreksi (belum ada yang ditulis)');
    return $o;
}

/** @return list<string> files */
function sc_write_outputs(array $plan, array $wb, string $dir): array
{
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("cannot create output directory {$dir}");
    }
    $files = [];
    $csv = static function (string $file, array $cols, array $rows) use ($dir, &$files): void {
        $f = fopen($dir . '/' . $file, 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, $cols, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($f, array_map(static fn ($c) => is_array($r[$c] ?? '') ? implode(' | ', $r[$c]) : ($r[$c] ?? ''), $cols), ',', '"', '');
        }
        fclose($f);
        $files[] = $dir . '/' . $file;
    };
    $flat = [];
    foreach ($plan['rows'] as $r) {
        $m = $r['mapping'];
        $flat[] = ['source_row' => $r['source_row'], 'source_item_code' => $r['master_sku'], 'master_item_id' => $r['item_id'], 'workbook_name' => $r['workbook_name'], 'item_name' => $r['item_name'], 'base_unit' => $r['base_unit'],
            'old_opening_qty' => sc_fmt($r['old_qty'], 6), 'admin_corrected_opening_qty' => sc_fmt($r['new_qty'], 6), 'delta_qty' => sc_fmt($r['delta_qty'], 6), 'old_opening_hpp' => sc_fmt($r['old_unit_cost'], 6), 'corrected_opening_hpp' => sc_fmt($r['new_unit_cost'], 6),
            'old_opening_value' => sc_fmt($r['old_value'], 4), 'corrected_opening_value' => sc_fmt($r['new_value'], 4), 'delta_value' => sc_fmt($r['delta_value'], 4), 'final_condition' => $r['condition'], 'correction_type' => implode(', ', $r['types']),
            'keterangan_2' => $r['ket2'], 'parsed_interpretation' => implode(' ; ', $r['explanation']), 'qty_source' => $r['qty_source'], 'hpp_source' => $r['hpp_source'], 'item_resolution' => $r['resolve_how'],
            'mapping_kind' => $m['kind'] ?? '', 'mapping_phrase' => $m['phrase'] ?? '', 'mapping_from' => $m['from_sku'] ?? '', 'mapping_to' => $m['to_sku'] ?? '', 'fifo_class' => $r['fifo_class'], 'planned_action' => $r['action'], 'status' => $r['status'], 'blocker' => implode(' | ', $r['blockers'])];
    }
    $cols = array_keys($flat[0] ?? ['source_row' => 1]);
    $csv('scm_admin_corrections_all_rows.csv', $cols, $flat);
    $sub = static fn (string $t) => array_values(array_filter($flat, static fn ($x) => in_array($t, array_map('trim', explode(',', $x['correction_type'])), true)));
    $csv('scm_qty_corrections.csv', $cols, $sub('QTY_CORRECTION'));
    $csv('scm_mapping_corrections.csv', $cols, $sub('ITEM_MAPPING_CORRECTION'));
    $csv('scm_uom_corrections.csv', $cols, $sub('UOM_CORRECTION'));
    $csv('scm_hpp_corrections.csv', $cols, $sub('HPP_VALUE_CORRECTION'));
    $csv('scm_condition_corrections.csv', $cols, $sub('CONDITION_CORRECTION'));
    $fifo = [];
    foreach ($plan['rows'] as $r) {
        if ($r['ket2'] === '' || $r['fifo'] === null) {
            continue;
        }
        $f = $r['fifo'];
        $fifo[] = ['source_row' => $r['source_row'], 'master_sku' => $r['master_sku'], 'item_name' => $r['item_name'], 'base_unit' => $r['base_unit'], 'original_opening_fifo_qty' => sc_fmt($f['open_qty'], 6), 'consumed_qty_since_1_oct' => sc_fmt($f['consumed_since_qty'], 6),
            'remaining_opening_qty' => sc_fmt($f['remaining_open'], 6), 'old_unit_cost' => sc_fmt($f['old_unit_cost'], 6), 'corrected_unit_cost' => sc_fmt($f['new_unit_cost'], 6), 'cogs_already_consumed' => sc_fmt($f['cogs_consumed'], 4),
            'retrospective_cogs_impact' => sc_fmt($f['cogs_restatement'] ?? 0.0, 4), 'affects_consumed_fifo' => ($f['cogs_restatement'] ?? 0) != 0 || ($f['cost_differs'] && $f['consumed_since_qty'] > 0) ? 'YES' : 'no', 'delta_qty' => sc_fmt($r['delta_qty'], 6), 'classification' => $r['fifo_class'], 'planned_action' => $r['action']];
    }
    $csv('scm_fifo_impact.csv', array_keys($fifo[0] ?? ['source_row' => 1]), $fifo);
    $csv('scm_blockers.csv', ['source_row', 'item', 'sku', 'code', 'detail'], $plan['blockers']);
    $rec = [];
    foreach ($plan['rows'] as $r) {
        if ($r['recon'] === null) {
            continue;
        }
        $x = $r['recon'];
        $corrected = $r['new_qty'] ?? $r['old_qty'];
        $expected = $corrected + $x['move_qty'];
        $oldExpected = $r['old_qty'] + $x['move_qty'];
        $correctedV = $r['new_value'] ?? $r['old_value'];
        $rec[] = ['source_row' => $r['source_row'], 'master_sku' => $r['master_sku'], 'item_name' => $r['item_name'], 'base_unit' => $r['base_unit'], 'old_opening_qty' => sc_fmt($r['old_qty'], 6), 'corrected_opening_qty' => sc_fmt($corrected, 6), 'stock_in_after_1_oct' => sc_fmt($x['in'], 6),
            'stock_out_after_1_oct' => sc_fmt($x['out'], 6), 'transfer_in' => sc_fmt($x['tin'], 6), 'transfer_out' => sc_fmt($x['tout'], 6), 'later_adjustments' => sc_fmt($x['adj'], 6), 'expected_current_after_correction' => sc_fmt($expected, 6), 'actual_current_system' => sc_fmt($x['actual_qty'], 6),
            'difference_before_posting' => sc_fmt($expected - $x['actual_qty'], 6), 'explained_by_correction_delta' => sc_fmt($r['delta_qty'] ?? 0.0, 6), 'unexplained_remaining' => sc_fmt($oldExpected - $x['actual_qty'], 6), 'status' => abs($oldExpected - $x['actual_qty']) <= 0.001 ? 'RECONCILED' : 'UNEXPLAINED_TRANSACTION',
            'expected_current_value' => sc_fmt($correctedV + $x['move_value'], 4), 'actual_current_value' => sc_fmt($x['actual_value'], 4), 'workbook_qty_scm_in_base_unit' => sc_fmt($x['workbook_scm_base'], 6),
            'workbook_vs_production_current' => abs($x['workbook_scm_base'] - $x['actual_qty']) <= 0.001 ? 'same' : 'differs (stock moved since the 7 Oct export, or export differs)'];
    }
    $csv('scm_current_reconciliation.csv', array_keys($rec[0] ?? ['source_row' => 1]), $rec);
    $summary = $plan['summary'];
    $summary['preview_sha'] = $plan['preview_sha'];
    $summary['source'] = $plan['source'];
    $summary['overrides'] = $plan['overrides'];
    $summary['warehouse'] = $plan['warehouse'];
    $summary['blocker_codes'] = array_count_values(array_column($plan['blockers'], 'code'));
    $summary['unexplained_rows'] = count(array_filter($rec, static fn ($x) => $x['status'] !== 'RECONCILED'));
    $summary['nothing_written'] = true;
    file_put_contents($dir . '/scm_admin_correction_summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $files[] = $dir . '/scm_admin_correction_summary.json';
    return $files;
}

/** Offline interpretation of every Keterangan 2 (no database). @return list<string> files */
function sc_write_interpretation(array $wb, string $dir): array
{
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("cannot create output directory {$dir}");
    }
    $cols = ['source_row', 'workbook_name', 'unit', 'qty_so', 'qty_scm', 'nominal_so', 'nominal_scm', 'workbook_keterangan', 'keterangan_2', 'parsed_types', 'explicit_qty', 'qty_unit_in_text', 'number_reading', 'nominal', 'price', 'condition', 'mapping_kind', 'mapping_phrase', 'flags', 'interpretation'];
    $f = fopen($dir . '/scm_keterangan2_interpretation.csv', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $cols, ',', '"', '');
    $by = [];
    foreach ($wb['rows'] as $r) {
        if ($r['ket2'] === '') {
            continue;
        }
        $i = sc_interpret($r);
        foreach ($i['types'] as $t) {
            $by[$t] = ($by[$t] ?? 0) + 1;
        }
        fputcsv($f, [$r['row'], $r['name'], $r['unit'], $r['qty_so'], $r['qty_scm'], $r['nom_so'], $r['nom_scm'], $r['ket'], $r['ket2'], implode(', ', $i['types']), $i['qty'] ?? '', $i['qty_unit'] ?? '', $i['qty_note'], $i['nominal'] ?? '', $i['price'] ?? '',
            implode(', ', $i['conditions']), $i['mapping']['kind'] ?? '', $i['mapping']['target_text'] ?? '', implode(' ; ', $i['flags']), implode(' ; ', $i['explanation'])], ',', '"', '');
    }
    fclose($f);
    ksort($by);
    file_put_contents($dir . '/scm_keterangan2_interpretation_summary.json', json_encode(['rows_with_keterangan2' => count(array_filter($wb['rows'], static fn ($r) => $r['ket2'] !== '')), 'parsed_types (a row may carry several)' => $by, 'workbook_sha256' => $wb['sha256']], JSON_PRETTY_PRINT));
    return [$dir . '/scm_keterangan2_interpretation.csv', $dir . '/scm_keterangan2_interpretation_summary.json'];
}

// ============================================================================ post (the only writer — not issued yet)
/** @return array<string,mixed> */
function sc_post(PDO $pdo, array $plan, string $actorUsername, string $previewSha): array
{
    if ($plan['blocked']) {
        $already = array_filter($plan['blockers'], static fn ($b) => str_starts_with((string) $b['detail'], 'SCM_OPENING_CORRECTION_ALREADY_POSTED'));
        if ($already) {
            throw new ScException('SCM_OPENING_CORRECTION_ALREADY_POSTED', 'the correction ' . SC_REFERENCE . ' is already posted — nothing was written', 12);
        }
        throw new ScException('BLOCKED', 'refused — ' . count($plan['blockers']) . ' blocker(s); nothing was written', 11);
    }
    if (!hash_equals($plan['preview_sha'], $previewSha)) {
        throw new ScException('PREVIEW_MISMATCH', "preview sha256 mismatch: current plan is {$plan['preview_sha']}, you passed {$previewSha} — re-run preview and review it again", 13);
    }
    $u = $pdo->prepare('SELECT u.id, u.username, u.is_active, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = :u');
    $u->execute(['u' => $actorUsername]);
    $actor = $u->fetch();
    if (!$actor || (int) $actor['is_active'] !== 1 || $actor['role_code'] !== 'SUPERADMIN') {
        throw new ScException('ACTOR_INVALID', "--actor must be an ACTIVE SUPERADMIN user (got '{$actorUsername}')", 14);
    }
    $ready = array_values(array_filter($plan['rows'], static fn ($r) => $r['status'] === 'READY'));
    if ($ready === []) {
        throw new ScException('NOTHING_TO_POST', 'no correction to post', 11);
    }
    $whId = (int) $plan['warehouse']['id'];
    return Database::transaction(function (PDO $tx) use ($plan, $ready, $actor, $whId) {
        $posted = [];
        $seq = 0;
        foreach ($ready as $r) {
            $reason = 'ADMIN_KETERANGAN_2: ' . $r['ket2'];
            $common = ['item_id' => (int) $r['item_id'], 'warehouse_id' => $whId, 'adjustment_type' => 'CORRECTION', 'reason' => $reason, 'reference_no' => SC_REFERENCE, 'created_by' => (int) $actor['id'], 'username' => (string) $actor['username'], 'transaction_date' => SC_TX_INSTANT];
            $uuid = static function (string $part) use ($r): string {
                return 'SCM-ADMCORR-20261001-' . $r['item_id'] . '-' . $part;
            };
            $before = \App\Services\InventoryService::currentStock($tx, (int) $r['item_id'], $whId);
            $steps = [];
            if ($r['action'] === 'INCREASE') {
                $steps[] = ['delta' => (float) $r['delta_qty'], 'cost' => round((float) $r['delta_value'] / (float) $r['delta_qty'], 6), 'part' => 'INC'];
            } elseif ($r['action'] === 'DECREASE') {
                $steps[] = ['delta' => (float) $r['delta_qty'], 'cost' => null, 'part' => 'DEC'];
            } elseif ($r['action'] === 'REVALUE_PAIR') {
                $steps[] = ['delta' => -(float) $r['old_qty'], 'cost' => null, 'part' => 'REVAL-OUT'];
                $steps[] = ['delta' => (float) $r['new_qty'], 'cost' => (float) $r['new_unit_cost'], 'part' => 'REVAL-IN'];
            }
            $txIds = [];
            foreach ($steps as $s) {
                if (abs($s['delta']) < 1e-9) {
                    continue;
                }
                $p = $common + ['transaction_uuid' => $uuid($s['part']), 'qty_base_delta' => $s['delta']];
                if ($s['cost'] !== null) {
                    $p['override_cost_base'] = $s['cost'];
                }
                $res = StockAdjustmentService::post($tx, $p);
                $txIds[] = $res['transaction_id'];
                $seq++;
            }
            $after = \App\Services\InventoryService::currentStock($tx, (int) $r['item_id'], $whId);
            $expectAfter = round($before['qty_base'] + (float) $r['delta_qty'], 6);
            if (abs($after['qty_base'] - $expectAfter) > 0.0005) {
                throw new ScException('POST_VERIFY_FAILED', "item {$r['master_sku']}: on-hand after = {$after['qty_base']}, expected {$expectAfter} — rolled back", 16);
            }
            AuditService::log($tx, (int) $actor['id'], (string) $actor['username'], 'SCM_OPENING_CORRECTION_LINE', 'inventory_transactions', $txIds[0] ?? null, null, [
                'reference' => SC_REFERENCE, 'effective_date' => SC_EFFECTIVE_DATE, 'transaction_instant' => SC_TX_INSTANT, 'source_row' => $r['source_row'], 'item_id' => $r['item_id'], 'sku' => $r['master_sku'], 'reason' => 'ADMIN_KETERANGAN_2', 'keterangan_2' => $r['ket2'],
                'correction_types' => $r['types'], 'fifo_class' => $r['fifo_class'], 'action' => $r['action'], 'old_qty' => $r['old_qty'], 'corrected_qty' => $r['new_qty'], 'delta_qty' => $r['delta_qty'], 'old_value' => $r['old_value'], 'corrected_value' => $r['new_value'],
                'delta_value' => $r['delta_value'], 'final_condition' => $r['condition'], 'condition_note' => 'kondisi fisik dicatat di audit koreksi (ledger / FIFO tidak menyimpan kondisi); qty tidak dikeluarkan dari stok', 'mapping' => $r['mapping'], 'transaction_ids' => $txIds,
                'qty_source' => $r['qty_source'], 'hpp_source' => $r['hpp_source'], 'preview_sha256' => $plan['preview_sha'], 'overrides_digest' => $plan['overrides']['digest'], 'posted_by' => $actor['username'],
            ], SC_REFERENCE);
            $posted[] = ['item_id' => $r['item_id'], 'tx' => $txIds];
        }
        AuditService::log($tx, (int) $actor['id'], (string) $actor['username'], 'SCM_OPENING_CORRECTION_POST', 'warehouses', $whId, null, [
            'reference' => SC_REFERENCE, 'effective_date' => SC_EFFECTIVE_DATE, 'workbook_sha256' => $plan['source']['sha256'], 'preview_sha256' => $plan['preview_sha'], 'overrides_sha256' => $plan['overrides']['sha256'], 'items' => count($posted),
            'old_opening_value' => $plan['summary']['old_scm_opening_value_all_items'], 'corrected_opening_value' => $plan['summary']['corrected_scm_opening_value'], 'delta_value' => $plan['summary']['delta_value_of_non_blocked_corrections'],
        ], SC_REFERENCE);
        return ['items' => count($posted), 'transactions' => $seq, 'delta_value' => $plan['summary']['delta_value_of_non_blocked_corrections']];
    });
}

/** read-only post-write verification from the audit records of the posted correction @return list<array{0:string,1:bool,2:string}> */
function sc_verify(PDO $pdo, string $warehouseCode = SC_WAREHOUSE_CODE): array
{
    $out = [];
    $st = sc_load_state($pdo, $warehouseCode);
    $out[] = ['the correction ' . SC_REFERENCE . ' is posted', $st['already_posted'] > 0, $st['already_posted'] . ' transactions'];
    if ($st['already_posted'] === 0) {
        return $out;
    }
    $lines = [];
    foreach ($pdo->query("SELECT after_data FROM audit_logs WHERE action_code = 'SCM_OPENING_CORRECTION_LINE' AND reason = '" . SC_REFERENCE . "'")->fetchAll(PDO::FETCH_COLUMN) as $j) {
        $d = json_decode((string) $j, true);
        if (is_array($d)) {
            $lines[(int) $d['item_id']] = $d;
        }
    }
    $out[] = ['one audit line per corrected item', $lines !== [], count($lines) . ' items'];
    $bad = [];
    $sumNew = 0.0;
    foreach ($lines as $id => $d) {
        $l = $st['ledger'][$id] ?? ['open_qty' => 0.0, 'open_value' => 0.0];
        if (abs($l['open_qty'] - (float) $d['corrected_qty']) > 0.001 || abs($l['open_value'] - (float) $d['corrected_value']) > max(SC_VALUE_TOL, 0.0005 * abs((float) $d['corrected_value']))) {
            $bad[] = "{$d['sku']}: ledger opening {$l['open_qty']} / {$l['open_value']} vs corrected {$d['corrected_qty']} / {$d['corrected_value']}";
        }
        $sumNew += $l['open_value'];
    }
    $out[] = ['October opening (ledger, reporting date < 2026-10-01) of every corrected item == the admin-corrected quantity and value', $bad === [], $bad === [] ? 'all items' : implode('; ', array_slice($bad, 0, 5))];
    $td = $st['td'];
    $inOct = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions t WHERE t.reference_no = '" . SC_REFERENCE . "' AND {$td} >= '" . SC_OPEN_BOUNDARY . "'")->fetchColumn();
    $types = $pdo->query("SELECT DISTINCT transaction_type FROM inventory_transactions WHERE reference_no = '" . SC_REFERENCE . "'")->fetchAll(PDO::FETCH_COLUMN);
    $out[] = ['no correction transaction is dated in October; all are ADJUSTMENT-type dated ' . SC_TX_INSTANT . ' (never Purchase / Stock IN / OUT / Transfer)', $inOct === 0 && $types === ['ADJUSTMENT'], 'in October: ' . $inOct . ' types: ' . implode(',', $types)];
    $sessionTx = (int) $pdo->query("SELECT COUNT(*) FROM stock_adjustments sa JOIN inventory_transactions t ON t.id = sa.transaction_id WHERE t.reference_no = '" . SC_REFERENCE . "' AND sa.adjustment_type = 'OPNAME'")->fetchColumn();
    $out[] = ['no Stock Opname adjustment was created or changed by the correction', $sessionTx === 0, (string) $sessionTx];
    $unexpl = 0;
    foreach ($lines as $id => $d) {
        $l = $st['ledger'][$id] ?? ['open_qty' => 0.0, 'm_in' => 0, 'm_out' => 0, 'm_tin' => 0, 'm_tout' => 0, 'm_adj' => 0];
        $cur = $st['current'][$id]['qty'] ?? 0.0;
        if (abs($cur - ($l['open_qty'] + $l['m_in'] + $l['m_out'] + $l['m_tin'] + $l['m_tout'] + $l['m_adj'])) > 0.001) {
            $unexpl++;
        }
    }
    $out[] = ['current stock == corrected opening + post-opening movements for every corrected item (difference 0)', $unexpl === 0, "{$unexpl} items differ"];
    if (class_exists('App\\Services\\MovementReportV3Service')) {
        $o = \App\Services\MovementReportV3Service::overview($pdo, '2026-10-01', '2026-10-31', (int) $st['wh_id'])['split_totals'];
        $s = \App\Services\MovementReportV3Service::overview($pdo, '2026-09-01', '2026-09-30', (int) $st['wh_id'])['split_totals'];
        $out[] = ['Reports V3 (SCM): October Stok Awal == September Stok Akhir (the correction is in the opening, not in October movement)', abs((float) $o['opening'] - (float) $s['closing']) < 0.01, sprintf('opening %.4f closing %.4f', $o['opening'], $s['closing'])];
        $out[] = ['Reports V3 (SCM): identity opening + IN − OUT + TIN − TOUT + ADJ = closing in September and October', abs((float) $o['difference']) < 0.01 && abs((float) $s['difference']) < 0.01, sprintf('%.4f / %.4f', $s['difference'], $o['difference'])];
    }
    return $out;
}
