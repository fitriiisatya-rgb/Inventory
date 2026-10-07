<?php
declare(strict_types=1);

/**
 * Karang Tengah opening — BLOCKER RESOLUTION (read-only analysis) — library for scripts/rv3/kt_blocker_resolution.php.
 *
 * Production Master Barang is the source of truth. For every NOT_FOUND source row the production master (codes, normalised codes, barcodes, names, historical transaction names,
 * earlier approved SO / cutover mappings) is searched and the row is classified:
 *
 *   A  EXISTING_ITEM_WRONG_SOURCE_CODE   the same item exists under another master code (identity proven by an earlier approved mapping, a barcode, or an exact name / historical name)
 *   B  EXISTING_ITEM_NAME_VARIANT        the same item exists, the name differs slightly (identity proven by the normalised code, or by ONE overwhelmingly similar name with the same pack size)
 *   C  TRULY_NEW_MASTER                  nothing plausible exists → a creation PLAN only (nothing is created here)
 *   D  AMBIGUOUS                         more than one plausible item, one weak candidate, a pack-size conflict, or a candidate already used by another source row → BLOCKED, candidates listed
 *
 * For every UNIT_UNRESOLVED row (and every mapped candidate whose unit would not resolve) the unit evidence is collected: master base unit, EVERY version of item_unit_conversions,
 * the conversion factors of historical Stock IN lines posted in that unit, approved unit_conversion_candidates, the pack size written in the item name and the price-implied factor.
 * A factor is PROPOSED only when the hard evidence names exactly ONE value; the name hint and the price ratio are shown but never sufficient; conflicts and silence BLOCK.
 *
 * Nothing is written, created, guessed or changed: no master item, no conversion, no ledger row. The approved outcome is a CSV (karang_resolutions_PROPOSED.csv, approved_by empty) that
 * a person completes; kt_opening.php preview --resolutions=<csv> applies ONLY the approved rows to the opening mapping (kt_load_resolutions) — Master Barang stays untouched.
 */

const KR_PLAUSIBLE = 0.60;     // a name this similar is a candidate worth listing
const KR_AUTO_FUZZY = 0.90;    // a single fuzzy-only candidate must reach this (and the same pack size) to be proposed

// ============================================================================ normalisation
/** Code compared WITHOUT formatting: case, spaces, dashes, dots, underscores and leading zeros ignored. */
function kr_norm_code(string $c): string
{
    $x = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($c), 'UTF-8')) ?? '';
    $t = ltrim($x, '0');
    return $t === '' ? $x : $t;
}

/**
 * @return array{text:string,tokens:list<string>,sizes:list<string>}  sizes are canonical "<number><dimension>" (weights in G, volumes in ML, counts as PCS) so 1KG == 1000G
 */
function kr_norm_name(string $n): array
{
    $u = mb_strtoupper(str_replace("\u{00A0}", ' ', trim($n)), 'UTF-8');
    $sizes = [];
    $u = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*(KGS?|GRAM|GR|G|ML|LTR|LITER|L|PCS|PC|PAK|PACK)\b/u', static function (array $m) use (&$sizes): string {
        $v = (float) str_replace(',', '.', $m[1]);
        $unit = $m[2];
        if (in_array($unit, ['KG', 'KGS'], true)) {
            $v *= 1000;
            $d = 'G';
        } elseif (in_array($unit, ['GRAM', 'GR', 'G'], true)) {
            $d = 'G';
        } elseif (in_array($unit, ['LTR', 'LITER', 'L'], true)) {
            $v *= 1000;
            $d = 'ML';
        } elseif ($unit === 'ML') {
            $d = 'ML';
        } else {
            $d = 'PCS';
        }
        $tok = rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') . $d;
        $sizes[] = $tok;
        return ' SZ' . $tok . ' ';
    }, $u) ?? $u;
    $u = trim((string) preg_replace('/[^A-Z0-9]+/u', ' ', $u));
    $tokens = array_values(array_unique(array_filter(explode(' ', $u), static fn ($t) => $t !== '')));
    sort($tokens);
    $sizes = array_values(array_unique($sizes));
    sort($sizes);
    return ['text' => implode(' ', $tokens), 'tokens' => $tokens, 'sizes' => $sizes];
}

/** 0..1 — mean of the token Jaccard and the character similarity of the order-independent texts; equal token sets = 1. */
function kr_score(array $a, array $b): float
{
    if ($a['text'] === '' || $b['text'] === '') {
        return 0.0;
    }
    if ($a['text'] === $b['text']) {
        return 1.0;
    }
    $i = count(array_intersect($a['tokens'], $b['tokens']));
    $u = count(array_unique(array_merge($a['tokens'], $b['tokens'])));
    $j = $u > 0 ? $i / $u : 0.0;
    similar_text($a['text'], $b['text'], $pct);
    return round(($j + $pct / 100) / 2, 4);
}

/** SAME | CONFLICT | ONE_SIDED | NONE */
function kr_size_status(array $a, array $b): string
{
    if ($a['sizes'] === [] && $b['sizes'] === []) {
        return 'NONE';
    }
    if ($a['sizes'] === [] || $b['sizes'] === []) {
        return 'ONE_SIDED';
    }
    return $a['sizes'] === $b['sizes'] ? 'SAME' : 'CONFLICT';
}

/** dimension of a unit name: G (weight) | ML (volume) | PCS (count) | '' (unknown) */
function kr_unit_dim(string $u): string
{
    $n = kt_norm_unit($u);
    if (in_array($n, ['KG', 'KGS', 'GRAM', 'GR', 'G', 'KILOGRAM', 'MG'], true)) {
        return 'G';
    }
    if (in_array($n, ['ML', 'LTR', 'LITER', 'L', 'LITRE'], true)) {
        return 'ML';
    }
    if (in_array($n, ['PCS', 'PC', 'PACK', 'PAK', 'SET', 'JAR', 'ROLL', 'PAIL', 'BTL', 'BOTOL', 'DUS', 'KARTON', 'BUNGKUS', 'SACHET', 'LEMBAR', 'LBR'], true)) {
        return 'PCS';
    }
    return '';
}

/** base units in ONE unit of a standard physical unit (KG→G 1000, GRAM→KG 0.001 …) — only within the SAME dimension, never weight↔volume. */
function kr_physical_factor(string $from, string $to): ?float
{
    $scale = static function (string $u): ?array {
        $n = kt_norm_unit($u);
        return match (true) {
            in_array($n, ['KG', 'KGS', 'KILOGRAM'], true) => ['G', 1000.0],
            in_array($n, ['GRAM', 'GR', 'G'], true) => ['G', 1.0],
            $n === 'MG' => ['G', 0.001],
            in_array($n, ['LTR', 'LITER', 'L', 'LITRE'], true) => ['ML', 1000.0],
            $n === 'ML' => ['ML', 1.0],
            default => null,
        };
    };
    $a = $scale($from);
    $b = $scale($to);
    if ($a === null || $b === null || $a[0] !== $b[0]) {
        return null;
    }
    return round($a[1] / $b[1], 9);
}

// ============================================================================ production master (SELECT only)
/** @return array<string,mixed> */
function kr_load_master(PDO $pdo): array
{
    $try = static function (string $sql, array $bind = []) use ($pdo): array {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            return $st->fetchAll();
        } catch (PDOException) {
            return [];   // an optional evidence table that this installation does not have
        }
    };
    $items = [];
    foreach ($pdo->query("SELECT i.id, i.sku, i.name, i.status, i.barcode, i.category_id, c.name AS category_name, i.base_unit_id, u.code AS base_code, u.name AS base_name
                            FROM items i JOIN units u ON u.id = i.base_unit_id LEFT JOIN categories c ON c.id = i.category_id")->fetchAll() as $r) {
        $r['id'] = (int) $r['id'];
        $r['n'] = kr_norm_name((string) $r['name']);
        $r['nc'] = kr_norm_code((string) $r['sku']);
        $items[$r['id']] = $r;
    }
    $barcode = [];   // normalised barcode => [item_id]
    foreach ($items as $i) {
        if ($i['barcode'] !== null && trim((string) $i['barcode']) !== '') {
            $barcode[kr_norm_code((string) $i['barcode'])][$i['id']] = true;
        }
    }
    foreach ($try('SELECT item_id, barcode FROM item_barcodes WHERE is_active = 1') as $b) {
        $barcode[kr_norm_code((string) $b['barcode'])][(int) $b['item_id']] = true;
    }
    $alias = [];     // item_id => list<[normalised name, raw name, count]>
    foreach ($try('SELECT item_id, item_name_snapshot AS nm, COUNT(*) AS n FROM inventory_transaction_lines GROUP BY item_id, item_name_snapshot') as $a) {
        $alias[(int) $a['item_id']][] = ['n' => kr_norm_name((string) $a['nm']), 'raw' => (string) $a['nm'], 'count' => (int) $a['n']];
    }
    $prior = [];     // normalised source code => [item_id => list<origin>]
    foreach ($try('SELECT source_code, item_id FROM stock_opname_reference_item_mappings WHERE is_active = 1') as $p) {
        $prior[kr_norm_code((string) $p['source_code'])][(int) $p['item_id']][] = 'stock_opname_reference_item_mappings (disetujui)';
    }
    foreach ($try("SELECT DISTINCT source_code, item_id FROM stock_opname_reference_rows WHERE item_id IS NOT NULL AND mapping_status = 'MATCHED'") as $p) {
        $prior[kr_norm_code((string) $p['source_code'])][(int) $p['item_id']][] = 'stock_opname_reference_rows MATCHED';
    }
    foreach ($try("SELECT DISTINCT source_sku, item_id FROM warehouse_cutover_lines WHERE item_id IS NOT NULL AND mapping_status = 'MATCHED'") as $p) {
        $prior[kr_norm_code((string) $p['source_sku'])][(int) $p['item_id']][] = 'warehouse_cutover_lines MATCHED';
    }
    $conv = [];      // item_id => unit_id => list of every version
    foreach ($try('SELECT item_id, unit_id, conversion_to_base, valid_from, valid_to, note FROM item_unit_conversions ORDER BY valid_from') as $c) {
        $conv[(int) $c['item_id']][(int) $c['unit_id']][] = ['factor' => (float) $c['conversion_to_base'], 'valid_from' => $c['valid_from'], 'valid_to' => $c['valid_to'], 'note' => $c['note']];
    }
    $cand = [];      // sku (upper) => unit_conversion_candidates row
    foreach ($try('SELECT * FROM unit_conversion_candidates') as $c) {
        $cand[mb_strtoupper(trim((string) $c['sku']), 'UTF-8')] = $c;
    }
    $price = [];     // item_id => latest unit_cost_base (reference price series)
    foreach ($try('SELECT p.item_id, p.unit_cost_base FROM item_price_history p JOIN (SELECT item_id, MAX(id) AS mid FROM item_price_history GROUP BY item_id) x ON x.mid = p.id') as $p) {
        $price[(int) $p['item_id']] = (float) $p['unit_cost_base'];
    }
    return ['items' => $items, 'barcode' => $barcode, 'alias' => $alias, 'prior' => $prior, 'conv' => $conv, 'cand' => $cand, 'price' => $price, 'try' => $try];
}

// ============================================================================ NOT_FOUND classification
/**
 * @param array<string,mixed> $row   a kt_plan() row
 * @param array<string,bool>  $sourceCodes upper-cased codes present in the source workbook
 * @return array<string,mixed>
 */
function kr_classify(array $row, array $M, array $sourceCodes): array
{
    $src = kr_norm_name((string) $row['source_name']);
    $nc = kr_norm_code((string) $row['source_code']);
    $cands = [];
    foreach ($M['items'] as $id => $it) {
        $ev = [];
        $identity = [];   // evidence that proves WHICH item this is (not a mere resemblance)
        if ($nc !== '' && strlen($nc) >= 3 && $it['nc'] === $nc && mb_strtoupper(trim((string) $it['sku']), 'UTF-8') !== mb_strtoupper((string) $row['source_code'], 'UTF-8')) {
            $ev[] = 'KODE_NORMAL_SAMA';
            $identity['code'] = true;
        }
        if ($nc !== '' && isset($M['barcode'][$nc][$id])) {
            $ev[] = 'BARCODE_SAMA';
            $identity['barcode'] = true;
        }
        if (isset($M['prior'][$nc][$id])) {
            $ev[] = 'PEMETAAN_TERDAHULU(' . implode('; ', array_unique($M['prior'][$nc][$id])) . ')';
            $identity['prior'] = true;
        }
        $score = kr_score($src, $it['n']);
        $sizeStatus = kr_size_status($src, $it['n']);
        $aliasHit = null;
        foreach ($M['alias'][$id] ?? [] as $a) {
            $s2 = kr_score($src, $a['n']);
            if ($s2 > $score) {
                $score = $s2;
            }
            if ($a['n']['text'] !== '' && $a['n']['text'] === $src['text'] && $aliasHit === null) {
                $aliasHit = $a;
            }
        }
        if ($src['text'] !== '' && $it['n']['text'] === $src['text']) {
            $ev[] = 'NAMA_NORMAL_SAMA';
            $identity['name'] = true;
        } elseif ($aliasHit !== null) {
            $ev[] = "NAMA_RIWAYAT_TRANSAKSI_SAMA(\"{$aliasHit['raw']}\" ×{$aliasHit['count']})";
            $identity['alias'] = true;
        }
        if ($ev === [] && $score < KR_PLAUSIBLE) {
            continue;
        }
        if ($ev === [] || ($score < 1.0 && !isset($identity['name']) && !isset($identity['alias']))) {
            $ev[] = sprintf('NAMA_MIRIP(%.2f)', $score);
        }
        $used = mb_strtoupper(trim((string) $it['sku']), 'UTF-8');
        $cands[] = ['item_id' => $id, 'sku' => $it['sku'], 'name' => $it['name'], 'status' => $it['status'], 'base_unit' => $it['base_code'], 'category_id' => $it['category_id'], 'category_name' => $it['category_name'],
            'score' => $score, 'size_status' => $sizeStatus, 'evidence' => $ev, 'identity' => $identity, 'used_by_source_code' => isset($sourceCodes[$used]) ? $it['sku'] : null];
    }
    usort($cands, static fn ($a, $b) => [count($b['identity']), $b['score']] <=> [count($a['identity']), $a['score']]);
    $viable = array_values(array_filter($cands, static fn ($c) => $c['used_by_source_code'] === null));
    $cls = ['class' => '', 'confidence' => '', 'reason' => '', 'proposal' => null, 'candidates' => array_slice($cands, 0, 5), 'viable' => count($viable), 'nearest' => $cands[0] ?? null];

    if ($viable === []) {
        if ($cands !== [] && ($cands[0]['identity'] !== [] || $cands[0]['score'] >= KR_AUTO_FUZZY)) {
            $cls['class'] = 'AMBIGUOUS';
            $cls['reason'] = "kandidat {$cands[0]['sku']} sudah dipakai sebagai kode sumber lain di file ini (pemetaan ganda ke satu Master Barang) — keputusan manual";
            $cls['confidence'] = 'LOW';
            return $cls;
        }
        $cls['class'] = 'TRULY_NEW_MASTER';
        $cls['confidence'] = $cands === [] ? 'HIGH' : 'MEDIUM';
        $cls['reason'] = $cands === [] ? 'tidak ada kode / barcode / nama / nama riwayat / pemetaan terdahulu yang cocok di Master Barang' : sprintf('kandidat terdekat hanya mirip %.2f dan sudah dipakai kode lain (%s) — bukan item yang sama', $cands[0]['score'], $cands[0]['sku']);
        return $cls;
    }
    $top = $viable[0];
    // identity proof = an earlier approved mapping, a barcode, or an exact (normalised / historical) name — a lone candidate with such proof beats mere resemblance of the others
    $strongIdentity = array_values(array_filter($viable, static fn ($c) => isset($c['identity']['prior']) || isset($c['identity']['barcode']) || isset($c['identity']['name']) || isset($c['identity']['alias'])));
    if (count($viable) > 1) {
        if (count($strongIdentity) === 1) {
            $top = $strongIdentity[0];
        } else {
            $cls['class'] = 'AMBIGUOUS';
            $cls['confidence'] = 'LOW';
            $cls['reason'] = count($viable) . ' kandidat masuk akal — tidak dipetakan berdasarkan kemiripan nama: ' . implode(' | ', array_map(static fn ($c) => "{$c['sku']} {$c['name']} (" . implode(',', $c['evidence']) . ')', array_slice($viable, 0, 3)));
            return $cls;
        }
    }
    if ($top['size_status'] === 'CONFLICT' && !isset($top['identity']['prior']) && !isset($top['identity']['barcode'])) {
        $cls['class'] = 'AMBIGUOUS';
        $cls['confidence'] = 'LOW';
        $cls['reason'] = "ukuran kemasan di nama berbeda (sumber vs master {$top['sku']}) — bisa jadi item lain";
        return $cls;
    }
    $nameProof = isset($top['identity']['name']) || isset($top['identity']['alias']);
    $idProof = isset($top['identity']['prior']) || isset($top['identity']['barcode']);
    $codeProof = isset($top['identity']['code']);
    if ($idProof || ($nameProof && in_array($top['size_status'], ['SAME', 'NONE'], true))) {
        $cls['class'] = 'EXISTING_ITEM_WRONG_SOURCE_CODE';
        $cls['confidence'] = ($idProof || isset($top['identity']['name'])) ? 'HIGH' : 'MEDIUM';
        $cls['reason'] = 'item yang sama sudah ada dengan kode master lain: ' . implode(', ', $top['evidence']);
    } elseif ($codeProof && $top['score'] >= KR_PLAUSIBLE && in_array($top['size_status'], ['SAME', 'NONE'], true)) {
        $cls['class'] = 'EXISTING_ITEM_NAME_VARIANT';
        $cls['confidence'] = $top['score'] >= KR_AUTO_FUZZY ? 'HIGH' : 'MEDIUM';
        $cls['reason'] = sprintf('kode sama setelah normalisasi (format kode berbeda) dan nama mirip %.2f: %s', $top['score'], implode(', ', $top['evidence']));
    } elseif (!$codeProof && $top['score'] >= KR_AUTO_FUZZY && $top['size_status'] === 'SAME' && count($viable) === 1) {
        $cls['class'] = 'EXISTING_ITEM_NAME_VARIANT';
        $cls['confidence'] = 'MEDIUM';
        $cls['reason'] = sprintf('satu-satunya kandidat, nama mirip %.2f dengan ukuran kemasan yang sama (hanya kemiripan nama — tinjau)', $top['score']);
    } else {
        $cls['class'] = 'AMBIGUOUS';
        $cls['confidence'] = 'LOW';
        $cls['reason'] = sprintf('satu kandidat lemah %s %s (kemiripan %.2f, ukuran %s%s) — bukti tidak cukup untuk memetakan', $top['sku'], $top['name'], $top['score'], $top['size_status'], $codeProof ? ', kode sama tetapi nama berbeda jauh' : '');
        return $cls;
    }
    $cls['proposal'] = $top;
    return $cls;
}

// ============================================================================ unit evidence
/**
 * @param array<string,mixed> $row a kt_plan() row; $item the master item (array from kr_load_master) the unit is evaluated against
 * @param array<string,mixed> $km  kt_master() (units + active conversions at the effective date)
 * @return array<string,mixed>
 */
function kr_unit_case(array $row, array $item, array $M, array $km, string $effectiveDate, string $origin): array
{
    $uom = (string) $row['source_uom'];
    $named = kt_units_named($km['units'], $uom);
    $namedIds = array_map(static fn ($u) => (int) $u['id'], $named);
    $baseName = (string) $item['base_code'];
    $out = ['source_row' => $row['source_row'], 'source_code' => $row['source_code'], 'source_name' => $row['source_name'], 'source_uom' => $uom, 'source_qty' => $row['source_qty'], 'source_hpp' => $row['source_hpp'], 'source_value' => $row['source_value'],
        'item_id' => $item['id'], 'master_sku' => $item['sku'], 'master_name' => $item['name'], 'master_base_unit' => $baseName, 'origin' => $origin,
        'master_conversions' => '', 'history' => '', 'candidates_table' => '', 'name_hint' => '', 'physical_standard' => '', 'price_implied_factor' => '', 'distinct_factors' => [],
        'verdict' => '', 'confidence' => '', 'proposed_factor' => null, 'basis' => '', 'reason' => '', 'norm_qty' => null, 'norm_hpp' => null, 'norm_value' => null, 'value_difference' => null];
    $evidence = [];   // factor (string) => list<basis>

    // 1. every version of item_unit_conversions for this item and the source unit
    $convText = [];
    foreach ($namedIds as $uid) {
        foreach ($M['conv'][$item['id']][$uid] ?? [] as $c) {
            $active = $c['valid_from'] <= $effectiveDate . ' 00:00:00' && ($c['valid_to'] === null || $c['valid_to'] > $effectiveDate . ' 00:00:00');
            $convText[] = sprintf('1 %s = %s %s [%s → %s%s]', $uom, rtrim(rtrim(number_format($c['factor'], 6, '.', ''), '0'), '.'), $baseName, $c['valid_from'], $c['valid_to'] ?? 'terbuka', $active ? ' — AKTIF pada tanggal efektif' : ' — TIDAK aktif pada tanggal efektif');
            $evidence[rtrim(rtrim(number_format($c['factor'], 9, '.', ''), '0'), '.')][] = 'item_unit_conversions' . ($active ? '' : ' (versi tidak aktif)');
        }
    }
    $out['master_conversions'] = $convText === [] ? 'tidak ada baris item_unit_conversions untuk satuan ini' : implode(' ; ', $convText);

    // 2. historical Stock IN lines posted in that unit: the factor the system itself used
    $hist = [];
    if ($namedIds !== []) {
        $in = implode(',', $namedIds);
        $hist = ($M['try'])("SELECT l.conversion_factor_snapshot AS f, COUNT(*) AS n, MIN(t.transaction_date) AS d0, MAX(t.transaction_date) AS d1
                               FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id
                              WHERE l.item_id = :i AND l.input_unit_id IN ({$in}) AND t.status = 'POSTED' AND t.transaction_type IN ('IN','OPENING','PRODUCTION_IN','TRANSFER_IN')
                              GROUP BY l.conversion_factor_snapshot", ['i' => $item['id']]);
    }
    $histText = [];
    foreach ($hist as $h) {
        $f = (float) $h['f'];
        $histText[] = sprintf('1 %s = %s %s ×%d baris (%s .. %s)', $uom, rtrim(rtrim(number_format($f, 6, '.', ''), '0'), '.'), $baseName, (int) $h['n'], substr((string) $h['d0'], 0, 10), substr((string) $h['d1'], 0, 10));
        $evidence[rtrim(rtrim(number_format($f, 9, '.', ''), '0'), '.')][] = 'riwayat transaksi (' . (int) $h['n'] . ' baris)';
    }
    $out['history'] = $histText === [] ? 'tidak ada transaksi masuk berkonversi dengan satuan ini' : implode(' ; ', $histText);

    // 3. approved unit_conversion_candidates (a person approved it)
    $uc = $M['cand'][mb_strtoupper(trim((string) $item['sku']), 'UTF-8')] ?? null;
    if ($uc !== null && ($uc['approved'] ?? null) === 'YES') {
        $ct = [];
        foreach ([['approved_middle_unit', 'approved_middle_conversion'], ['approved_purchase_unit', 'approved_purchase_conversion']] as [$cu, $cf]) {
            if (!empty($uc[$cu]) && $uc[$cf] !== null && kt_norm_unit((string) $uc[$cu]) === kt_norm_unit($uom)) {
                $f = (float) $uc[$cf];
                $ct[] = sprintf('1 %s = %s %s (unit_conversion_candidates APPROVED)', $uom, rtrim(rtrim(number_format($f, 6, '.', ''), '0'), '.'), $baseName);
                $evidence[rtrim(rtrim(number_format($f, 9, '.', ''), '0'), '.')][] = 'unit_conversion_candidates (APPROVED)';
            }
        }
        $out['candidates_table'] = implode(' ; ', $ct);
    }

    // 4. standard physical scale inside ONE dimension (KG↔GRAM, LTR↔ML) — not a business guess, never weight↔volume
    $phys = kr_physical_factor($uom, $baseName);
    if ($phys !== null) {
        $out['physical_standard'] = sprintf('1 %s = %s %s (skala satuan fisik standar)', $uom, rtrim(rtrim(number_format($phys, 9, '.', ''), '0'), '.'), $baseName);
        $evidence[rtrim(rtrim(number_format($phys, 9, '.', ''), '0'), '.')][] = 'skala satuan fisik standar';
    }

    // 5. hints that are SHOWN but are never sufficient
    $nameN = kr_norm_name((string) $item['name'] . ' ' . (string) $row['source_name']);
    $hint = null;
    if (kr_unit_dim($uom) === 'PCS' && count($nameN['sizes']) >= 1) {
        $baseDim = kr_unit_dim($baseName);
        $sizes = array_values(array_filter($nameN['sizes'], static fn ($s) => str_ends_with($s, $baseDim === 'G' ? 'G' : ($baseDim === 'ML' ? 'ML' : '#'))));
        if (count($sizes) === 1 && ($baseDim === 'G' || $baseDim === 'ML')) {
            $amount = (float) preg_replace('/[^0-9.]/', '', (string) $sizes[0]);
            $baseScale = $baseDim === 'G' ? (kr_physical_factor($baseName, 'GRAM') ?? 1.0) : (kr_physical_factor($baseName, 'ML') ?? 1.0);
            $hint = round($amount / $baseScale, 9);
            $out['name_hint'] = sprintf('nama menyebut ukuran %s → 1 %s = %s %s kalau itu isi satu %s (HANYA petunjuk, bukan bukti)', $sizes[0], $uom, rtrim(rtrim(number_format($hint, 9, '.', ''), '0'), '.'), $baseName, $uom);
        }
    }
    $mp = $M['price'][$item['id']] ?? null;
    if ($mp !== null && $mp > 0 && (float) $row['source_hpp'] > 0) {
        $out['price_implied_factor'] = sprintf('HPP sumber %s ÷ harga referensi master %s per %s = %s %s per %s (HANYA petunjuk, bukan bukti)', number_format((float) $row['source_hpp'], 4, '.', ''), number_format($mp, 4, '.', ''), $baseName, rtrim(rtrim(number_format((float) $row['source_hpp'] / $mp, 6, '.', ''), '0'), '.'), $baseName, $uom);
    }

    // verdict
    $factors = array_keys($evidence);
    $out['distinct_factors'] = $factors;
    if ($namedIds === []) {
        $out['verdict'] = 'BLOCKED';
        $out['confidence'] = 'NONE';
        $out['reason'] = "satuan sumber \"{$uom}\" tidak ada di master units — perlu keputusan bisnis (satuan baru / salah ketik); tidak ada bukti konversi";
    } elseif (count($factors) === 1) {
        $f = (float) $factors[0];
        if ($hint !== null && abs($hint - $f) > 1e-6) {
            $out['verdict'] = 'BLOCKED';
            $out['confidence'] = 'NONE';
            $out['reason'] = sprintf('bukti keras menyebut %s tetapi nama barang menyiratkan %s — konflik, perlu konfirmasi bisnis', $factors[0], rtrim(rtrim(number_format($hint, 9, '.', ''), '0'), '.'));
        } else {
            $kinds = array_unique(array_map(static fn ($b) => preg_replace('/ \(.*/', '', $b), $evidence[$factors[0]]));
            $out['verdict'] = 'RESOLVED_PROPOSED';
            $out['proposed_factor'] = $f;
            $out['confidence'] = count($kinds) >= 2 ? 'HIGH' : 'MEDIUM';
            $out['basis'] = implode(' + ', array_unique($evidence[$factors[0]]));
            $out['reason'] = 'satu nilai faktor dari bukti keras: ' . $out['basis'];
        }
    } elseif (count($factors) > 1) {
        $out['verdict'] = 'BLOCKED';
        $out['confidence'] = 'NONE';
        $out['reason'] = 'bukti saling bertentangan: ' . implode(' vs ', array_map(static fn ($f) => "{$f} (" . implode(', ', array_unique($evidence[$f])) . ')', $factors));
    } else {
        $out['verdict'] = 'BLOCKED';
        $out['confidence'] = 'NONE';
        $out['reason'] = 'tidak ada bukti konversi yang andal (tidak ada baris konversi, riwayat transaksi, kandidat yang disetujui, maupun skala fisik standar) — perlu konfirmasi bisnis; konversi TIDAK ditebak';
    }
    if ($out['proposed_factor'] !== null && $row['source_qty'] !== null) {
        $f = (float) $out['proposed_factor'];
        $out['norm_qty'] = round((float) $row['source_qty'] * $f, 6);
        $out['norm_hpp'] = round((float) $row['source_hpp'] / $f, 4);
        $out['norm_value'] = round($out['norm_qty'] * $out['norm_hpp'], 4);
        $out['value_difference'] = round($out['norm_value'] - (float) $row['source_value'], 4);
    }
    return $out;
}

// ============================================================================ the whole analysis
/**
 * @param array<string,mixed> $plan   kt_plan() WITHOUT resolutions (the baseline)
 * @param array<string,mixed> $source kt_read_source()
 * @return array<string,mixed>
 */
function kr_resolve(PDO $pdo, array $plan, array $source, string $effectiveDate = KT_EFFECTIVE_DATE): array
{
    $M = kr_load_master($pdo);
    $km = kt_master($pdo, KT_WAREHOUSE_CODE, $effectiveDate);
    $sourceCodes = [];
    foreach ($source['rows'] as $r) {
        $sourceCodes[mb_strtoupper($r['code'], 'UTF-8')] = true;
    }
    $notFound = [];
    $ambiguous = [];
    $newMaster = [];
    $unitRows = [];
    $proposals = [];
    $mappedItems = [];   // item_id => list<source code>

    foreach ($plan['rows'] as $row) {
        if ($row['status'] !== 'NOT_FOUND') {
            continue;
        }
        $c = $row['source_code'] === '' ? ['class' => 'AMBIGUOUS', 'confidence' => 'LOW', 'reason' => 'kode barang kosong di file sumber — tidak dapat dicari', 'proposal' => null, 'candidates' => [], 'viable' => 0, 'nearest' => null] : kr_classify($row, $M, $sourceCodes);
        $notFound[] = ['row' => $row, 'c' => $c];
        if ($c['proposal'] !== null) {
            $mappedItems[$c['proposal']['item_id']][] = $row['source_code'];
        }
    }
    // one master item proposed for two source rows = ambiguous
    foreach ($notFound as &$nf) {
        $p = $nf['c']['proposal'];
        if ($p !== null && count($mappedItems[$p['item_id']]) > 1) {
            $nf['c']['class'] = 'AMBIGUOUS';
            $nf['c']['confidence'] = 'LOW';
            $nf['c']['reason'] = "Master Barang {$p['sku']} diusulkan untuk lebih dari satu kode sumber (" . implode(', ', $mappedItems[$p['item_id']]) . ') — keputusan manual';
            $nf['c']['proposal'] = null;
        }
    }
    unset($nf);

    $counts = ['not_found_total' => count($notFound), 'not_found_blocking' => 0, 'mapped_existing' => 0, 'mapped_wrong_code' => 0, 'mapped_name_variant' => 0, 'truly_new' => 0, 'ambiguous' => 0,
        'unit_unresolved_total' => 0, 'unit_resolved' => 0, 'unit_still_blocked' => 0, 'unit_after_mapping_total' => 0, 'unit_after_mapping_resolved' => 0, 'unit_after_mapping_blocked' => 0];
    foreach ($notFound as $nk => $nf) {
        $row = $nf['row'];
        $c = $nf['c'];
        $positive = $row['source_qty'] !== null && $row['source_qty'] > 0;
        $counts['not_found_blocking'] += $positive ? 1 : 0;
        $p = $c['proposal'];
        $unitCheck = '';
        if ($c['class'] === 'EXISTING_ITEM_WRONG_SOURCE_CODE' || $c['class'] === 'EXISTING_ITEM_NAME_VARIANT') {
            $counts['mapped_existing']++;
            $counts[$c['class'] === 'EXISTING_ITEM_WRONG_SOURCE_CODE' ? 'mapped_wrong_code' : 'mapped_name_variant']++;
            $item = $M['items'][$p['item_id']];
            // would the unit resolve against the proposed item, exactly as the opening mapping resolves it?
            $named = kt_units_named($km['units'], (string) $row['source_uom']);
            $base = array_filter($named, static fn ($u) => (int) $u['id'] === (int) $item['base_unit_id']);
            $hit = $base !== [] || kt_norm_unit((string) $row['source_uom']) === kt_norm_unit((string) $item['base_code']) || kt_norm_unit((string) $row['source_uom']) === kt_norm_unit((string) $item['base_name']);
            $activeConv = count($named) === 1 ? ($km['conv'][$item['id']][(int) $named[0]['id']] ?? []) : [];
            if ($hit) {
                $unitCheck = "OK — satuan sumber = satuan dasar {$item['base_code']} (×1)";
            } elseif (count($activeConv) === 1) {
                $unitCheck = "OK — 1 {$row['source_uom']} = {$activeConv[0]} {$item['base_code']} (item_unit_conversions aktif)";
            } else {
                $unitCheck = "UNIT_UNRESOLVED setelah pemetaan: satuan sumber {$row['source_uom']} vs satuan dasar master {$item['base_code']}";
                $uc = kr_unit_case($row, $item, $M, $km, $effectiveDate, 'AFTER_PROPOSED_MAPPING');
                $unitRows[] = $uc;
                $counts['unit_after_mapping_total']++;
                $counts[$uc['verdict'] === 'RESOLVED_PROPOSED' ? 'unit_after_mapping_resolved' : 'unit_after_mapping_blocked']++;
                if ($uc['verdict'] === 'RESOLVED_PROPOSED' && $item['status'] === 'ACTIVE') {
                    $proposals[] = ['kind' => 'UNIT_FACTOR', 'source_code' => $row['source_code'], 'item_id' => '', 'master_sku' => '', 'source_uom' => $row['source_uom'], 'factor' => $uc['proposed_factor'],
                        'evidence' => $uc['basis'] . ' — setelah pemetaan ke ' . $item['sku'], 'approved_by' => ''];
                }
            }
            if ($item['status'] !== 'ACTIVE') {
                $unitCheck .= ' ; MASTER INACTIVE — opening akan terblokir ITEM_INACTIVE sampai master diaktifkan secara manual (tidak dilakukan di sini)';
            } else {
                $proposals[] = ['kind' => 'MAP_ITEM', 'source_code' => $row['source_code'], 'item_id' => $item['id'], 'master_sku' => $item['sku'], 'source_uom' => '', 'factor' => '', 'evidence' => $c['class'] . ': ' . $c['reason'], 'approved_by' => ''];
            }
        } elseif ($c['class'] === 'AMBIGUOUS') {
            $counts['ambiguous']++;
            $ambiguous[] = ['origin' => 'NOT_FOUND', 'row' => $row, 'c' => $c];
        } else {
            $counts['truly_new']++;
            $newMaster[] = kr_new_master_line($row, $M, $km);
        }
        $notFound[$nk]['unit_check'] = $unitCheck;
    }
    // sanity of the stated unit against the name for every NOT_FOUND row (e.g. "AIR MINERAL 220 ML" counted in Gram)
    foreach ($notFound as $k => $nf) {
        $notFound[$k]['unit_sanity'] = kr_unit_sanity((string) $nf['row']['source_name'], (string) $nf['row']['source_uom']);
    }

    // rows the plan already marked AMBIGUOUS (duplicate codes, one code → several masters, one master ← several codes)
    foreach ($plan['rows'] as $row) {
        if ($row['status'] !== 'AMBIGUOUS') {
            continue;
        }
        $cands = [];
        foreach ($M['items'] as $it) {
            if (mb_strtoupper(trim((string) $it['sku']), 'UTF-8') === mb_strtoupper((string) $row['source_code'], 'UTF-8')) {
                $cands[] = ['item_id' => $it['id'], 'sku' => $it['sku'], 'name' => $it['name'], 'status' => $it['status'], 'base_unit' => $it['base_code'], 'score' => 1.0, 'size_status' => '', 'evidence' => ['KODE_SAMA_PERSIS'], 'identity' => ['code' => true], 'used_by_source_code' => null];
            }
        }
        $ambiguous[] = ['origin' => 'PLAN_AMBIGUOUS', 'row' => $row, 'c' => ['class' => 'AMBIGUOUS', 'confidence' => 'LOW', 'reason' => $row['issue'], 'candidates' => $cands, 'proposal' => null, 'viable' => count($cands), 'nearest' => null]];
        $counts['ambiguous_in_plan'] = ($counts['ambiguous_in_plan'] ?? 0) + 1;
    }

    // UNIT_UNRESOLVED rows of the baseline plan (mapped by exact code)
    foreach ($plan['rows'] as $row) {
        if ($row['status'] !== 'UNIT_UNRESOLVED' || $row['item_id'] === null) {
            continue;
        }
        $counts['unit_unresolved_total']++;
        $uc = kr_unit_case($row, $M['items'][(int) $row['item_id']], $M, $km, $effectiveDate, 'PLAN_UNIT_UNRESOLVED');
        $unitRows[] = $uc;
        $counts[$uc['verdict'] === 'RESOLVED_PROPOSED' ? 'unit_resolved' : 'unit_still_blocked']++;
        if ($uc['verdict'] === 'RESOLVED_PROPOSED') {
            $proposals[] = ['kind' => 'UNIT_FACTOR', 'source_code' => $row['source_code'], 'item_id' => '', 'master_sku' => '', 'source_uom' => $row['source_uom'], 'factor' => $uc['proposed_factor'], 'evidence' => $uc['basis'], 'approved_by' => ''];
        }
    }
    return ['counts' => $counts, 'not_found' => $notFound, 'ambiguous' => $ambiguous, 'new_master' => $newMaster, 'units' => $unitRows, 'proposals' => $proposals, 'baseline_by_status' => $plan['by_status'], 'baseline_preview_sha' => $plan['preview_sha']];
}

/** "AIR MINERAL 220 ML" counted in "Gram" etc.: the pack size in the name is a volume / weight but the stated unit is the other dimension. */
function kr_unit_sanity(string $name, string $uom): string
{
    $n = kr_norm_name($name);
    $d = kr_unit_dim($uom);
    $dims = array_unique(array_map(static fn ($s) => str_ends_with($s, 'ML') ? 'ML' : (str_ends_with($s, 'G') ? 'G' : 'PCS'), $n['sizes']));
    foreach ($dims as $nd) {
        if (($nd === 'ML' && $d === 'G') || ($nd === 'G' && $d === 'ML')) {
            return "UOM_NAMA_TIDAK_SEJALAN: nama menyebut ukuran volume/berat ({$name}) tetapi satuan sumber \"{$uom}\" berdimensi berbeda — konfirmasi bisnis sebelum dipakai";
        }
    }
    if ($d === '') {
        return "UOM_TIDAK_DIKENAL: \"{$uom}\"";
    }
    return '';
}

/** @return array<string,mixed> one line of the (plan-only) master creation proposal */
function kr_new_master_line(array $row, array $M, array $km): array
{
    $named = kt_units_named($km['units'], (string) $row['source_uom']);
    $baseCode = count($named) === 1 ? (string) $named[0]['code'] : '';
    $baseStatus = count($named) === 1 ? "satuan sumber \"{$row['source_uom']}\" = master unit {$baseCode} (tepat satu)" : (count($named) === 0 ? "satuan sumber \"{$row['source_uom']}\" tidak ada di master units — perlu keputusan" : 'satuan sumber cocok dengan lebih dari satu master unit — perlu keputusan');
    $name = trim((string) preg_replace('/\s+/u', ' ', (string) $row['source_name']));
    $sanity = kr_unit_sanity((string) $row['source_name'], (string) $row['source_uom']);
    // category: suggested from the most similar existing items — NEVER created, never assigned
    $src = kr_norm_name((string) $row['source_name']);
    $votes = [];
    foreach ($M['items'] as $it) {
        if ($it['category_id'] === null) {
            continue;
        }
        $s = kr_score($src, $it['n']);
        if ($s >= 0.4) {
            $votes[(string) $it['category_name']] = ($votes[(string) $it['category_name']] ?? 0) + $s;
        }
    }
    arsort($votes);
    $sug = $votes === [] ? '' : (string) array_key_first($votes);
    $blockers = [];
    if ($baseCode === '') {
        $blockers[] = 'satuan dasar belum pasti';
    }
    if ($sanity !== '') {
        $blockers[] = $sanity;
    }
    if ((float) $row['source_hpp'] <= 0) {
        $blockers[] = 'HPP sumber kosong / 0';
    }
    return ['source_row' => $row['source_row'], 'code' => $row['source_code'], 'item_name' => $name, 'name_note' => $name !== trim((string) $row['source_name']) ? 'spasi ganda dirapikan; nama selebihnya persis dari sumber' : 'persis dari sumber',
        'category_id' => '', 'category_name' => '', 'category_decision' => 'PERLU KONFIRMASI (kosong = Tanpa Kategori; kategori tidak dibuat otomatis)', 'suggested_category' => $sug,
        'suggestion_basis' => $sug === '' ? 'tidak ada item mirip berkategori' : 'kategori terbanyak dari item master yang namanya mirip',
        'base_unit_code' => $baseCode, 'base_unit_basis' => $baseStatus, 'initial_hpp' => $row['source_hpp'], 'hpp_per' => (string) $row['source_uom'], 'active_status' => 'ACTIVE', 'default_supplier' => '',
        'supplier_note' => 'tidak diketahui — supplier tidak dibuat otomatis', 'opening_source_reference' => KT_REFERENCE, 'source_qty' => $row['source_qty'], 'source_uom' => $row['source_uom'], 'source_value' => $row['source_value'],
        'unit_sanity' => $sanity, 'ready_for_creation' => $blockers === [] ? 'YES' : 'NO', 'not_ready_reason' => implode('; ', $blockers)];
}

// ============================================================================ output
/** @return list<string> files */
function kr_write_outputs(array $r, array $plan, string $dir): array
{
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("cannot create output directory {$dir}");
    }
    $csv = static function (string $file, array $cols, array $rows) use ($dir): string {
        $f = fopen($dir . '/' . $file, 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, $cols, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($f, array_map(static fn ($c) => is_array($row[$c] ?? '') ? implode(' | ', $row[$c]) : ($row[$c] ?? ''), $cols), ',', '"', '');
        }
        fclose($f);
        return $dir . '/' . $file;
    };
    $files = [];
    $nf = [];
    foreach ($r['not_found'] as $x) {
        $row = $x['row'];
        $c = $x['c'];
        $p = $c['proposal'];
        $near = $p ?? $c['nearest'];
        $nf[] = ['source_row' => $row['source_row'], 'source_code' => $row['source_code'], 'source_name' => $row['source_name'], 'source_uom' => $row['source_uom'], 'source_qty' => $row['source_qty'], 'source_hpp' => $row['source_hpp'],
            'source_value' => $row['source_value'], 'blocking' => ($row['source_qty'] !== null && $row['source_qty'] > 0) ? 'YES' : 'no (qty 0)', 'classification' => $c['class'], 'confidence' => $c['confidence'],
            'proposed_action' => match ($c['class']) { 'EXISTING_ITEM_WRONG_SOURCE_CODE', 'EXISTING_ITEM_NAME_VARIANT' => ($near && $near['status'] === 'ACTIVE') ? 'MAP_ITEM (butuh persetujuan)' : 'MAP_ITEM ditahan — master INACTIVE', 'TRULY_NEW_MASTER' => 'CREATE_MASTER (rencana saja; lihat proposed_new_master.csv)', default => 'BLOCKED — keputusan manual (lihat ambiguous_items.csv)' },
            'candidate_item_id' => $p['item_id'] ?? '', 'candidate_master_code' => $p['sku'] ?? '', 'candidate_master_name' => $p['name'] ?? '', 'candidate_master_status' => $p['status'] ?? '', 'master_base_unit' => $p['base_unit'] ?? '',
            'evidence' => $p ? implode(', ', $p['evidence']) : '', 'reason' => $c['reason'], 'nearest_not_proposed' => ($p === null && $near) ? "{$near['sku']} {$near['name']} (kemiripan " . number_format((float) $near['score'], 2) . ')' : '',
            'unit_check' => $x['unit_check'] ?? '', 'unit_sanity' => $x['unit_sanity'] ?? ''];
    }
    $files[] = $csv('not_found_resolution.csv', ['source_row', 'source_code', 'source_name', 'source_uom', 'source_qty', 'source_hpp', 'source_value', 'blocking', 'classification', 'confidence', 'proposed_action', 'candidate_item_id', 'candidate_master_code',
        'candidate_master_name', 'candidate_master_status', 'master_base_unit', 'evidence', 'reason', 'nearest_not_proposed', 'unit_check', 'unit_sanity'], $nf);
    $files[] = $csv('unit_resolution.csv', ['source_row', 'source_code', 'source_name', 'origin', 'item_id', 'master_sku', 'master_name', 'master_base_unit', 'source_uom', 'source_qty', 'source_hpp', 'source_value', 'verdict', 'confidence', 'proposed_factor',
        'basis', 'reason', 'master_conversions', 'history', 'candidates_table', 'physical_standard', 'name_hint', 'price_implied_factor', 'norm_qty', 'norm_hpp', 'norm_value', 'value_difference'], $r['units']);
    $files[] = $csv('proposed_new_master.csv', ['source_row', 'code', 'item_name', 'name_note', 'category_id', 'category_name', 'category_decision', 'suggested_category', 'suggestion_basis', 'base_unit_code', 'base_unit_basis', 'initial_hpp', 'hpp_per',
        'active_status', 'default_supplier', 'supplier_note', 'opening_source_reference', 'source_qty', 'source_uom', 'source_value', 'unit_sanity', 'ready_for_creation', 'not_ready_reason'], $r['new_master']);
    $amb = [];
    foreach ($r['ambiguous'] as $a) {
        $row = $a['row'];
        $cands = $a['c']['candidates'] ?: [null];
        foreach ($cands as $i => $cd) {
            $amb[] = ['source_row' => $row['source_row'], 'source_code' => $row['source_code'], 'source_name' => $row['source_name'], 'source_uom' => $row['source_uom'], 'source_qty' => $row['source_qty'], 'source_hpp' => $row['source_hpp'],
                'origin' => $a['origin'], 'reason' => $a['c']['reason'], 'candidate_rank' => $cd === null ? '' : $i + 1, 'candidate_item_id' => $cd['item_id'] ?? '', 'candidate_master_code' => $cd['sku'] ?? '', 'candidate_master_name' => $cd['name'] ?? '',
                'candidate_status' => $cd['status'] ?? '', 'master_base_unit' => $cd['base_unit'] ?? '', 'similarity' => $cd === null ? '' : number_format((float) $cd['score'], 2), 'size_status' => $cd['size_status'] ?? '',
                'evidence' => $cd === null ? '' : implode(', ', $cd['evidence']), 'already_used_by_source_code' => $cd['used_by_source_code'] ?? ''];
        }
    }
    $files[] = $csv('ambiguous_items.csv', ['source_row', 'source_code', 'source_name', 'source_uom', 'source_qty', 'source_hpp', 'origin', 'reason', 'candidate_rank', 'candidate_item_id', 'candidate_master_code', 'candidate_master_name', 'candidate_status',
        'master_base_unit', 'similarity', 'size_status', 'evidence', 'already_used_by_source_code'], $amb);
    $files[] = $csv('karang_resolutions_PROPOSED.csv', ['kind', 'source_code', 'item_id', 'master_sku', 'source_uom', 'base_qty_per_source_unit', 'evidence', 'approved_by'],
        array_map(static fn ($p) => ['kind' => $p['kind'], 'source_code' => $p['source_code'], 'item_id' => $p['item_id'], 'master_sku' => $p['master_sku'], 'source_uom' => $p['source_uom'], 'base_qty_per_source_unit' => $p['factor'], 'evidence' => $p['evidence'], 'approved_by' => ''], $r['proposals']));
    $summary = kr_summary($r, $plan);
    file_put_contents($dir . '/blocker_resolution_summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $files[] = $dir . '/blocker_resolution_summary.json';
    return $files;
}

/** @return array<string,mixed> */
function kr_summary(array $r, array $plan): array
{
    $c = $r['counts'];
    $value = static function (callable $f) use ($r): float {
        $t = 0.0;
        foreach ($r['not_found'] as $x) {
            if ($f($x)) {
                $t += (float) $x['row']['source_value'];
            }
        }
        return round($t, 4);
    };
    return [
        'reference' => KT_REFERENCE, 'source_sha256' => $plan['source']['sha256'], 'baseline_preview_sha' => $r['baseline_preview_sha'], 'baseline_by_status' => $r['baseline_by_status'],
        'not_found' => ['total' => $c['not_found_total'], 'blocking_positive_qty' => $c['not_found_blocking'], 'mapped_existing' => $c['mapped_existing'], 'mapped_wrong_source_code' => $c['mapped_wrong_code'], 'mapped_name_variant' => $c['mapped_name_variant'],
            'truly_new' => $c['truly_new'], 'ambiguous' => $c['ambiguous'],
            'source_value_by_class' => ['mapped_existing' => $value(static fn ($x) => in_array($x['c']['class'], ['EXISTING_ITEM_WRONG_SOURCE_CODE', 'EXISTING_ITEM_NAME_VARIANT'], true)), 'truly_new' => $value(static fn ($x) => $x['c']['class'] === 'TRULY_NEW_MASTER'),
                'ambiguous' => $value(static fn ($x) => $x['c']['class'] === 'AMBIGUOUS')]],
        'unit_unresolved' => ['total' => $c['unit_unresolved_total'], 'resolved_proposed' => $c['unit_resolved'], 'still_blocked' => $c['unit_still_blocked']],
        'unit_after_proposed_mapping' => ['total' => $c['unit_after_mapping_total'], 'resolved_proposed' => $c['unit_after_mapping_resolved'], 'still_blocked' => $c['unit_after_mapping_blocked']],
        'plan_ambiguous_rows' => $c['ambiguous_in_plan'] ?? 0,
        'new_master' => ['planned' => count($r['new_master']), 'ready_for_creation' => count(array_filter($r['new_master'], static fn ($n) => $n['ready_for_creation'] === 'YES')), 'needs_confirmation' => count(array_filter($r['new_master'], static fn ($n) => $n['ready_for_creation'] !== 'YES'))],
        'proposed_resolution_rows' => ['MAP_ITEM' => count(array_filter($r['proposals'], static fn ($p) => $p['kind'] === 'MAP_ITEM')), 'UNIT_FACTOR' => count(array_filter($r['proposals'], static fn ($p) => $p['kind'] === 'UNIT_FACTOR')), 'approved' => 0],
        'path_to_clean_preview' => [
            '1_approve' => 'person fills approved_by on each reviewed row of karang_resolutions_PROPOSED.csv (MAP_ITEM / UNIT_FACTOR); unapproved rows are ignored by preview',
            '2_create_masters' => 'rows of proposed_new_master.csv need an explicit, separate apply step (not part of this analysis); the new code = the source code, so preview then matches them exactly',
            '3_decide' => 'ambiguous_items.csv and every BLOCKED unit row need a business decision (map to a candidate, confirm a factor, or fix the source)',
            '4_preview' => 'kt_opening.php preview --resolutions=<approved csv>; target NOT_FOUND = AMBIGUOUS = UNIT_UNRESOLVED = HPP_MISSING = ITEM_INACTIVE = QTY_INVALID = 0 and exit 0',
        ],
        'nothing_written' => true,
    ];
}

/** @return list<string> */
function kr_report_lines(array $r, array $plan): array
{
    $c = $r['counts'];
    $o = ['=== KARANG TENGAH — BLOCKER RESOLUTION (READ ONLY; nothing created, mapped or changed) ===', "Baseline preview sha256 : {$r['baseline_preview_sha']}   baris per status: " . implode(' · ', array_map(static fn ($k, $v) => "{$k} {$v}", array_keys($r['baseline_by_status']), $r['baseline_by_status'])), ''];
    $o[] = sprintf('NOT_FOUND total                         : %d   (qty > 0, memblokir: %d)', $c['not_found_total'], $c['not_found_blocking']);
    $o[] = sprintf('  → mapped existing (A + B)             : %d   (A kode master lain: %d · B varian nama: %d)', $c['mapped_existing'], $c['mapped_wrong_code'], $c['mapped_name_variant']);
    $o[] = sprintf('  → truly new master (C)                : %d', $c['truly_new']);
    $o[] = sprintf('  → ambiguous (D)                       : %d', $c['ambiguous']);
    $o[] = sprintf('UNIT_UNRESOLVED total                    : %d', $c['unit_unresolved_total']);
    $o[] = sprintf('  → resolved (usulan dengan bukti keras): %d', $c['unit_resolved']);
    $o[] = sprintf('  → still blocked (butuh konfirmasi)    : %d', $c['unit_still_blocked']);
    $o[] = sprintf('Unit setelah pemetaan usulan            : %d baris (usulan dengan bukti %d · masih terblokir %d)', $c['unit_after_mapping_total'], $c['unit_after_mapping_resolved'], $c['unit_after_mapping_blocked']);
    $o[] = sprintf('Baris AMBIGUOUS dari plan (kode ganda dst): %d', $c['ambiguous_in_plan'] ?? 0);
    $ready = count(array_filter($r['new_master'], static fn ($n) => $n['ready_for_creation'] === 'YES'));
    $o[] = sprintf('Rencana master baru                      : %d (siap dibuat %d · perlu konfirmasi %d) — TIDAK dibuat', count($r['new_master']), $ready, count($r['new_master']) - $ready);
    $o[] = '';
    $o[] = 'RINCIAN NOT_FOUND:';
    foreach ($r['not_found'] as $x) {
        $row = $x['row'];
        $cl = $x['c'];
        $p = $cl['proposal'];
        $o[] = sprintf('  baris %-4d %-8s %-44s %-5s qty %-10s HPP %-14s → %s%s', $row['source_row'], $row['source_code'], mb_substr(trim((string) $row['source_name']), 0, 44), $row['source_uom'], kt_fmt($row['source_qty'], 3), kt_fmt((float) $row['source_hpp'], 4), $cl['class'],
            $p ? " → item_id {$p['item_id']} {$p['sku']} \"{$p['name']}\" [{$p['base_unit']}] ({$cl['confidence']})" : '');
        if (($x['unit_check'] ?? '') !== '' && !str_starts_with($x['unit_check'], 'OK')) {
            $o[] = '            satuan: ' . $x['unit_check'];
        }
        if (($x['unit_sanity'] ?? '') !== '') {
            $o[] = '            PERHATIAN: ' . $x['unit_sanity'];
        }
    }
    $o[] = '';
    $o[] = 'RINCIAN UNIT:';
    foreach ($r['units'] as $u) {
        $o[] = sprintf('  baris %-4d %-8s %-40s 1 %s → %s   %s%s', $u['source_row'], $u['source_code'], mb_substr(trim((string) $u['source_name']), 0, 40), $u['source_uom'], $u['master_base_unit'], $u['verdict'],
            $u['proposed_factor'] !== null ? " faktor {$u['proposed_factor']} ({$u['confidence']}) nilai {$u['norm_value']} vs {$u['source_value']} (selisih {$u['value_difference']})" : ' — ' . $u['reason']);
    }
    return $o;
}
