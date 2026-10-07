<?php
declare(strict_types=1);

/**
 * SCM OPENING CORRECTION — Keterangan 2 of the admin-reviewed workbook (scripts/rv3/scm_corr_lib.php, scm_correction.php).
 *
 *   A. interpretation of the REAL workbook (tests/fixtures/scm/Perbandingan_Stok_SCM_vs_SO.xlsx): 127 Keterangan 2 texts, no database
 *   B. a production-like SCM ledger (September layers, October movements) + a synthetic workbook with every correction kind: qty up / down / zero, reduction larger than the remaining
 *      opening layer, HPP-only correction (safe / consumed → COGS restatement), item with no opening, kg → GR, pack → pcs (with / without a master conversion), mapping, informational text,
 *      item missing in the master, HPP basis confirmation, condition-only; FIFO classification, current-stock reconciliation, ten output files, read-only proof
 *   C. approved overrides, a clean correction posted: opening == corrected, nothing in October, ADJUSTMENT-type dated 2026-09-30 23:59:59 (never IN / OUT / Transfer / OPNAME), sessions and
 *      historical rows untouched, idempotency (SCM_OPENING_CORRECTION_ALREADY_POSTED), preview binding, actor, Reports V3 opening/closing continuity
 *
 * Usage: php tests/scm_correction_test.php
 */

foreach (glob(__DIR__ . '/../services/*.php') as $f) {
    if (basename($f) !== 'ReportsV3Routes.php') {
        require_once $f;
    }
}
require_once __DIR__ . '/../scripts/rv3/scm_corr_lib.php';

use App\Services\Database;
use App\Services\ExcelWriterService;
use App\Services\FifoService;
use App\Services\InventoryEffectiveDateService;
use App\Services\MovementReportV3Service as V3;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
$near = static fn (float $a, float $b, float $e = 0.01): bool => abs($a - $b) <= $e;
$root = dirname(__DIR__);
$real = $root . '/tests/fixtures/scm/Perbandingan_Stok_SCM_vs_SO.xlsx';

echo "== A. the REAL workbook: interpretation of every Keterangan 2 (no database) ==\n";
$wbReal = sc_read_workbook($real);
$with = array_values(array_filter($wbReal['rows'], static fn ($r) => $r['ket2'] !== ''));
check('A1 452 rows in Perbandingan, 127 carry a Keterangan 2; Data SCM provides the SKU of each key', count($wbReal['rows']) === 452 && count($with) === 127 && count($wbReal['scm_by_key']) >= 600);
$I = [];
foreach ($with as $r) {
    $I[$r['row']] = sc_interpret($r);
}
$t = static function (array $types, string $x): bool {
    return in_array($x, $types, true);
};
$cntType = array_count_values(array_merge(...array_map(static fn ($i) => $i['types'], $I)));
check('A2 types over the 127 texts: QTY 121 · CONDITION 33 · HPP 17 · MAPPING 7 · UOM 9 (a row may carry several)', ($cntType['QTY_CORRECTION'] ?? 0) === 121 && ($cntType['CONDITION_CORRECTION'] ?? 0) === 33 && ($cntType['HPP_VALUE_CORRECTION'] ?? 0) === 17 && ($cntType['ITEM_MAPPING_CORRECTION'] ?? 0) === 7 && ($cntType['UOM_CORRECTION'] ?? 0) === 9, json_encode($cntType));
check('A3 "yang betul stok 0" → explicit qty 0 (row 2); "yang betul 73.000 pcs" → 73000, read as thousands because the SO quantity is 73000 (row 4); "15.2" → 15.2 (row 118); "44.2" → 44.2 (row 34)', $I[2]['qty'] === 0.0 && $I[4]['qty'] === 73000.0 && str_contains($I[4]['qty_note'], 'thousands') && $I[118]['qty'] === 15.2 && $I[34]['qty'] === 44.2);
check('A4 nominal correction rows keep their quantity and carry the nominal: row 7 → 120.000, row 82 → 5.520.000', $I[7]['qty'] === null && $I[7]['nominal'] === 120000.0 && $I[82]['qty'] === null && $I[82]['nominal'] === 5520000.0);
check('A5 pack vs pcs (rows 3, 23, 38, 39, 42, 47, 122) and unit contradictions (109 "5 pak", 130 "228 kg") are UOM_CORRECTION', $t($I[3]['types'], 'UOM_CORRECTION') && $t($I[23]['types'], 'UOM_CORRECTION') && $t($I[109]['types'], 'UOM_CORRECTION') && $t($I[130]['types'], 'UOM_CORRECTION') && $t($I[122]['types'], 'UOM_CORRECTION'));
check('A6 row 23: 5500 pcs at harga 600 with the equivalent "550 pak harga 6000" recognised (price 600, alternative 550 × 6000)', $I[23]['qty'] === 5500.0 && $I[23]['price'] === 600.0 && $I[23]['alt']['qty'] === 550.0 && $I[23]['alt']['price'] === 6000.0);
check('A7 row 3: price listed per pak → value = 35 pak × SO price; row 109 is 5 pak', $I[3]['price_basis'] === 'PER_PAK' && $I[3]['qty'] === 35.0 && $I[3]['qty_unit'] === 'pak' && $I[109]['qty'] === 5.0 && $I[109]['qty_unit'] === 'pak');
check('A8 deadstock texts keep the quantity and the condition: row 20 (290 DEADSTOCK), row 28 has NO qty → SO quantity fallback; row 26 "stok 0 karena barang sudah expire" → qty 0 + EXPIRED', $I[20]['qty'] === 290.0 && $I[20]['condition'] === 'DEADSTOCK' && in_array('QTY_FROM_SO_FALLBACK', $I[28]['flags'], true) && $I[26]['qty'] === 0.0 && $I[26]['condition'] === 'EXPIRED');
check('A9 mapping phrases: 35 "sama dengan lilin kriting hijau" · 43 "di sistem ke hadwash jasmine" (qty 90) · 78 "salah nama harusnya susu kental manis tiga sapi" (qty 0) · 79 / 94 tapioka · 121 / 128 "diinput di mauripan"',
    $I[35]['mapping']['kind'] === 'SAME_AS' && $I[35]['mapping']['target_text'] === 'lilin kriting hijau' && $I[43]['mapping']['kind'] === 'SYSTEM_HAS_UNDER' && $I[43]['qty'] === 90.0 && $I[78]['mapping']['kind'] === 'WRONG_NAME' && $I[78]['qty'] === 0.0
    && $I[79]['mapping']['kind'] === 'SO_ENTERED_UNDER' && $I[94]['mapping']['kind'] === 'SYSTEM_HAS_UNDER' && $I[121]['mapping']['kind'] === 'ENTERED_UNDER' && $I[128]['mapping']['target_text'] === 'mauripan');
$tmp = sys_get_temp_dir() . '/scm_corr_' . bin2hex(random_bytes(4));
mkdir($tmp);
$files = sc_write_interpretation($wbReal, $tmp . '/interp');
check('A10 the interpretation csv lists all 127 texts with their explanation', count(file($tmp . '/interp/scm_keterangan2_interpretation.csv')) === 128 && is_file($tmp . '/interp/scm_keterangan2_interpretation_summary.json'));

// ================================================================== B. production-like ledger
echo "\n== B. production-like SCM ledger + synthetic workbook ==\n";
$pdo = Database::connection();
$unit = [];
foreach ($pdo->query('SELECT id, code FROM units')->fetchAll() as $u) {
    $unit[$u['code']] = (int) $u['id'];
}
$role = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$adm = (int) $pdo->query("SELECT id FROM roles WHERE code='ADMIN'")->fetchColumn();
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES ('sc_super','x','sc_super',:r,1), ('sc_admin','x','sc_admin',:a,1)")->execute(['r' => $role, 'a' => $adm]);
$superId = (int) $pdo->query("SELECT id FROM users WHERE username='sc_super'")->fetchColumn();
$pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('SCM', 'Gudang SCM', 'MAIN', 1)");
$wh = (int) $pdo->query("SELECT id FROM warehouses WHERE code='SCM'")->fetchColumn();
$mk = static function (string $sku, string $name, string $base) use ($pdo, $unit): int {
    $pdo->prepare("INSERT INTO items (sku, name, base_unit_id, status) VALUES (:s, :n, :u, 'ACTIVE')")->execute(['s' => $sku, 'n' => $name, 'u' => $unit[$base]]);
    return (int) $pdo->lastInsertId();
};
$in = static function (int $item, string $base, float $qty, float $cost, string $date, string $uuid) use ($pdo, $unit, $wh, $superId): void {
    FifoService::postIn($pdo, ['transaction_uuid' => $uuid, 'item_id' => $item, 'warehouse_id' => $wh, 'input_qty' => $qty, 'input_unit_id' => $unit[$base], 'unit_price_input' => $cost, 'transaction_type' => 'IN', 'transaction_date' => $date, 'created_by' => $superId, 'anomaly_approved_by' => $superId]);
};
$out = static function (int $item, string $base, float $qty, string $date, string $uuid) use ($pdo, $unit, $wh, $superId): void {
    FifoService::postOut($pdo, ['transaction_uuid' => $uuid, 'item_id' => $item, 'warehouse_id' => $wh, 'input_qty' => $qty, 'input_unit_id' => $unit[$base], 'transaction_type' => 'OUT', 'transaction_date' => $date, 'created_by' => $superId]);
};
$sku = [];
$sku['UP'] = $mk('RM-T-UP', 'Gula Up', 'KG');
$in($sku['UP'], 'KG', 10, 1000, '2026-09-05 09:00:00', 'T-UP-1');
$in($sku['UP'], 'KG', 3, 1000, '2026-10-03 09:00:00', 'T-UP-2');                                // an October purchase: current = opening + 3
$sku['DOWN'] = $mk('RM-T-DOWN', 'Dus Down', 'PCS');
$in($sku['DOWN'], 'PCS', 60, 50, '2026-09-01 09:00:00', 'T-DN-1');
$in($sku['DOWN'], 'PCS', 40, 60, '2026-09-10 09:00:00', 'T-DN-2');
$sku['ZERO'] = $mk('RM-T-ZERO', 'Terigu Zero', 'KG');
$in($sku['ZERO'], 'KG', 5, 8000, '2026-09-02 09:00:00', 'T-ZR-1');
$sku['EXC'] = $mk('RM-T-EXC', 'Plastik Exceed', 'PCS');
$in($sku['EXC'], 'PCS', 50, 100, '2026-09-02 09:00:00', 'T-EX-1');
$out($sku['EXC'], 'PCS', 40, '2026-10-04 09:00:00', 'T-EX-OUT');                                 // 40 consumed in October: only 10 of the opening layer remain
$sku['HSAFE'] = $mk('RM-T-HSAFE', 'Pewarna Hpp Safe', 'PCS');
$in($sku['HSAFE'], 'PCS', 100, 100, '2026-09-03 09:00:00', 'T-HS-1');
$sku['HCOGS'] = $mk('RM-T-HCOGS', 'Pewarna Hpp Cogs', 'PCS');
$in($sku['HCOGS'], 'PCS', 100, 100, '2026-09-03 09:00:00', 'T-HC-1');
$out($sku['HCOGS'], 'PCS', 30, '2026-10-05 09:00:00', 'T-HC-OUT');
$sku['NEW'] = $mk('RM-T-NEW', 'Patung Baru', 'PCS');                                          // no opening at all
$sku['KG'] = $mk('RM-T-KG', 'Pastricia Gr', 'GR');
$in($sku['KG'], 'GR', 50000, 1, '2026-09-04 09:00:00', 'T-KG-1');
$sku['GLOVE'] = $mk('RM-T-GLOVE', 'Hand Glove X', 'PCS');
$in($sku['GLOVE'], 'PCS', 3500, 6850, '2026-09-04 09:00:00', 'T-GL-1');
$pdo->prepare("INSERT INTO item_unit_conversions (item_id, unit_id, conversion_to_base, valid_from) VALUES (:i, :u, 100, '2025-01-01 00:00:00')")->execute(['i' => $sku['GLOVE'], 'u' => $unit['PACK']]);
$sku['FOTO'] = $mk('RM-T-FOTO', 'Kertas Foto X', 'PCS');
$in($sku['FOTO'], 'PCS', 100, 975, '2026-09-04 09:00:00', 'T-FT-1');
$sku['KRES'] = $mk('RM-T-KRES', 'Kresek Jumbo X', 'PCS');
$in($sku['KRES'], 'PCS', 6612, 1362, '2026-09-04 09:00:00', 'T-KR-1');
$sku['TG'] = $mk('RM-T-TG', 'Terigu Tali X', 'KG');
$in($sku['TG'], 'KG', 815, 9120, '2026-09-04 09:00:00', 'T-TG-1');
$sku['DS'] = $mk('RM-T-DS', 'Patung Naruto X', 'PCS');
$in($sku['DS'], 'PCS', 197, 11500, '2026-09-04 09:00:00', 'T-DS-1');
$sku['BON'] = $mk('RM-T-BON', 'Bonigrasa X', 'KG');
$in($sku['BON'], 'KG', 32, 12552.083333, '2026-09-04 09:00:00', 'T-BO-1');
$sku['SKM'] = $mk('RM-T-SKM', 'Susu Kental Manis Tiga Sapi X', 'KG');
$in($sku['SKM'], 'KG', 32, 12187.5, '2026-09-04 09:00:00', 'T-SK-1');
$sku['INFO'] = $mk('RM-T-INFO', 'Info Only', 'PCS');
$in($sku['INFO'], 'PCS', 7, 1000, '2026-09-04 09:00:00', 'T-IF-1');
$sku['RED'] = $mk('RM-T-RED', 'Red Handsoap X', 'LTR');

$hdr = ['Nama Barang', 'Satuan', 'Qty SO', 'Qty SCM', 'Selisih Qty', 'Nominal SO (Rp)', 'Nominal SCM (Rp)', 'Selisih Nominal (Rp)', 'Keterangan', 'Key', 'Satuan SCM', 'Faktor', 'Keterangan 2'];
$pb = [];
$scm = [];
$add = static function (string $name, string $unitSo, float $qSo, float $qScm, float $nSo, float $nScm, string $kUnit, float $factor, string $k2, ?string $skuKey) use (&$pb, &$scm): void {
    $key = sc_key($name);
    $pb[] = [$name, $unitSo, $qSo, $qScm, $qScm - $qSo, $nSo, $nScm, $nScm - $nSo, 'Selisih', $key, $kUnit, $factor, $k2];
    if ($skuKey !== null) {
        $scm[] = [$skuKey, $name, 'Cat', $kUnit, $qScm, 0, 'AMAN', $nScm, $key];
    }
};
$add('Gula Up', 'Kg', 15, 13, 15000, 13000, 'KG', 1, 'yang betul stok 15', 'RM-T-UP');
$add('Dus Down', 'Pcs', 80, 100, 4400, 5400, 'PCS', 1, 'yang betul stok 80', 'RM-T-DOWN');
$add('Terigu Zero', 'kg', 0, 5, 0, 40000, 'KG', 1, 'yang betul stok 0', 'RM-T-ZERO');
$add('Plastik Exceed', 'pcs', 0, 10, 0, 1000, 'PCS', 1, 'yang betul stok 0', 'RM-T-EXC');
$add('Pewarna Hpp Safe', 'Pcs', 100, 100, 5000, 10000, 'PCS', 1, 'nominal yang betul 5.000', 'RM-T-HSAFE');
$add('Pewarna Hpp Cogs', 'Pcs', 100, 100, 5000, 7000, 'PCS', 1, 'nominal yang betul 5.000', 'RM-T-HCOGS');
$add('Patung Baru', 'Pcs', 40, 0, 400000, 0, '', 1, 'yang betul stok 40 (deadstok)', null);
$add('Pastricia Gr', 'Kg', 60, 50, 3000000, 50, 'GR', 0.001, 'yang betul stok 60', 'RM-T-KG');
$add('Hand Glove X', 'Pcs', 35, 3500, 239750, 23975000, 'PCS', 1, 'Yang betul stok 35 pak karena harga yang tercantum per pak', 'RM-T-GLOVE');
$add('Kertas Foto X', 'Pack', 0, 100, 0, 97500, 'PCS', 1, 'yang betul stok 5 pak', 'RM-T-FOTO');
$add('Kresek Jumbo X', 'kg', 228, 6612, 9006000, 9006000, 'PCS', 1, 'yang betul 228 kg', 'RM-T-KRES');
$add('Terigu Tali X', 'kg', 815, 800, 10626000, 7296000, 'KG', 1, 'yang betul 800, di so nominal juga 800', 'RM-T-TG');
$add('Patung Naruto X', 'Pcs', 197, 197, 2265500, 2265500, 'PCS', 1, 'yang betul stok (deadstok)', 'RM-T-DS');
$add('Bonigrasa X', 'kg', 32, 0, 401666.67, 0, '', 1, 'yang betul stok 0, salah nama harusnya susu kental manis tiga sapi x', null);
$add('Susu Kental Manis Tiga Sapi X', 'kg', 0, 32, 0, 390000, 'KG', 1, 'yang betul stok 32', 'RM-T-SKM');
$add('Red Handsoap X', 'liter', 90, 0, 990000, 0, 'LTR', 1, 'yang betul red hand soap 90 di sistem ke hadwash jasmine', 'RM-T-RED');
$add('Info Only', 'Pcs', 7, 7, 7000, 7000, 'PCS', 1, 'terima kasih sudah dicek', 'RM-T-INFO');
$add('Barang Tidak Ada Di Master', 'Pcs', 12, 0, 120000, 0, '', 1, 'yang betul stok 12', null);
$add('Tanpa Keterangan', 'Pcs', 3, 3, 3000, 3000, 'PCS', 1, '', null);
$bPath = $tmp . '/synthetic.xlsx';
ExcelWriterService::write($bPath, ['Perbandingan' => ['headers' => $hdr, 'rows' => $pb], 'Data SCM' => ['headers' => ['SKU', 'Nama Produk', 'Kategori', 'Satuan', 'Stok Tersedia', 'Stok Minimal', 'Status', 'Nilai Stok (Rp)', 'Key'], 'rows' => $scm]]);
$wb = sc_read_workbook($bPath);
$none = sc_load_overrides(null);
$snapTables = ['inventory_transactions', 'inventory_transaction_lines', 'inventory_batches', 'fifo_allocations', 'stock_adjustments', 'stock_opname_sessions', 'stock_opname_lines', 'items', 'item_unit_conversions', 'audit_logs', 'inventory_effective_dates'];
$snap = static function () use ($pdo, $snapTables): array {
    $o = [];
    foreach ($snapTables as $t) {
        $o[$t] = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM)[1];
    }
    return $o;
};
$before = $snap();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
$plan = sc_plan($pdo, $wb, $none);
$pdo->exec('ROLLBACK');
$pdo->exec('SET SESSION TRANSACTION READ WRITE');
check('B0 the preview changed NOTHING (every ledger / FIFO / audit / master table checksum identical)', $snap() === $before);
$P = [];
foreach ($plan['rows'] as $r) {
    $P[$r['workbook_name']] = $r;
}
$codes = static fn (array $r): array => $r['blocker_codes'];
check('B1 the opening of each item comes from the ledger (reporting date < 2026-10-01), not from the workbook or the current stock: Gula Up 10 KG / Rp 10.000 although current stock is 13', $near($P['Gula Up']['old_qty'], 10.0) && $near($P['Gula Up']['old_value'], 10000.0) && $near($P['Gula Up']['recon']['actual_qty'], 13.0));
$x = $P['Gula Up'];
check('B2 QTY up (10 → 15 KG): SAFE_QTY_CORRECTION, INCREASE 5 at the existing cost 1.000 (HPP kept), corrected value 15.000', $x['status'] === 'READY' && $x['fifo_class'] === 'SAFE_QTY_CORRECTION' && $x['action'] === 'INCREASE' && $near($x['delta_qty'], 5.0) && $near($x['new_value'], 15000.0) && str_contains($x['hpp_source'], 'KEEP'), json_encode([$x['status'], $x['fifo_class'], $x['delta_qty'], $x['new_value']]));
$x = $P['Dus Down'];
check('B3 QTY down (100 → 80): value = the layers FIFO would really remove: 20 from the oldest layer @50 → 5.400 − 1.000 = 4.400, DECREASE, SAFE', $x['status'] === 'READY' && $x['action'] === 'DECREASE' && $near($x['delta_qty'], -20.0) && $near($x['new_value'], 4400.0) && $x['fifo_class'] === 'SAFE_QTY_CORRECTION', json_encode([$x['new_value'], $x['blockers']]));
$x = $P['Terigu Zero'];
check('B4 "stok 0": the whole opening is removed (5 KG, Rp 40.000 → 0); the zero is explicit', $x['status'] === 'READY' && $near($x['new_qty'], 0.0) && $near($x['new_value'], 0.0) && $near($x['delta_value'], -40000.0));
$x = $P['Plastik Exceed'];
check('B5 reduction 50 > the 10 that remain of the opening layer (40 were consumed in October) → BLOCKED REDUCTION_EXCEEDS_OPENING_LAYER, explained', $x['status'] === 'BLOCKED' && in_array('REDUCTION_EXCEEDS_OPENING_LAYER', $codes($x), true) && str_contains($x['blockers'][0], 'melebihi sisa layer'), json_encode($x['blockers']));
$x = $P['Pewarna Hpp Safe'];
check('B6 HPP-only correction ("nominal yang betul 5.000"), nothing consumed → SAFE_VALUE_CORRECTION, REVALUE_PAIR, qty unchanged 100, cost 100 → 50, Δ −5.000', $x['status'] === 'READY' && $x['fifo_class'] === 'SAFE_VALUE_CORRECTION' && $x['action'] === 'REVALUE_PAIR' && $near($x['new_qty'], 100.0) && $near($x['new_unit_cost'], 50.0) && $near($x['delta_value'], -5000.0) && in_array('HPP_VALUE_CORRECTION', $x['types'], true), json_encode([$x['fifo_class'], $x['new_unit_cost']]));
$x = $P['Pewarna Hpp Cogs'];
check('B7 the same correction when 30 of 100 were already consumed in October → REQUIRES_COGS_RESTATEMENT, BLOCKED; COGS already consumed Rp 3.000, restatement impact Rp −1.500; consumed layers are not edited', $x['status'] === 'BLOCKED' && $x['fifo_class'] === 'REQUIRES_COGS_RESTATEMENT' && $near($x['fifo']['cogs_consumed'], 3000.0) && $near($x['fifo']['cogs_restatement'], -1500.0) && $near($x['fifo']['consumed_since_qty'], 30.0), json_encode($x['fifo']));
$x = $P['Patung Baru'];
check('B8 item with NO opening: qty 40 + DEADSTOCK, cost from the SO price (400.000 / 40 = 10.000) → INCREASE; condition DEADSTOCK kept in the dataset; HPP source stated', $x['status'] === 'READY' && $x['action'] === 'INCREASE' && $near($x['new_value'], 400000.0) && $x['condition'] === 'DEADSTOCK' && str_contains($x['hpp_source'], 'SO_PRICE') && $x['item_id'] !== null, json_encode([$x['status'], $x['blockers'], $x['resolve_how']]));
$x = $P['Pastricia Gr'];
check('B9 kg → base GR: 60 kg = 60.000 GR (standard physical scale, not a guess); keeps cost 1/GR → +10.000 GR, value 60.000', $x['status'] === 'READY' && $near($x['new_qty'], 60000.0) && $near($x['delta_qty'], 10000.0) && $near($x['new_value'], 60000.0), json_encode([$x['new_qty'], $x['new_value'], $x['blockers']]));
$x = $P['Hand Glove X'];
check('B10 "35 pak, harga per pak": master conversion 1 PACK = 100 PCS → 3.500 PCS (qty unchanged), value = 35 × 6.850 = 239.750 (cost 6.850 → 68,5) → UOM + HPP correction, SAFE_VALUE_CORRECTION', $x['status'] === 'READY' && $near($x['new_qty'], 3500.0) && $near($x['new_value'], 239750.0) && $near($x['new_unit_cost'], 68.5, 0.001) && in_array('UOM_CORRECTION', $x['types'], true) && in_array('HPP_VALUE_CORRECTION', $x['types'], true) && $x['fifo_class'] === 'SAFE_VALUE_CORRECTION', json_encode([$x['status'], $x['blockers'], $x['new_value']]));
$x = $P['Kertas Foto X'];
check('B11 "5 pak" with NO PACK→PCS conversion in the master → BLOCKED UNIT_FACTOR_UNCONFIRMED (the ratio in the workbook is only a hint)', $x['status'] === 'BLOCKED' && in_array('UNIT_FACTOR_UNCONFIRMED', $codes($x), true));
$x = $P['Kresek Jumbo X'];
check('B12 "228 kg" for an item whose base unit is PCS (the workbook assumed factor 1) → BLOCKED UNIT_FACTOR_UNCONFIRMED; the 6.612 PCS are not silently relabelled', $x['status'] === 'BLOCKED' && in_array('UNIT_FACTOR_UNCONFIRMED', $codes($x), true));
$x = $P['Terigu Tali X'];
check('B13 "yang betul 800, di so nominal juga 800" while the SO price (13.038) ≠ the system cost (9.120) → BLOCKED HPP_BASIS_CONFIRMATION, both candidate costs shown (not assumed)', $x['status'] === 'BLOCKED' && in_array('HPP_BASIS_CONFIRMATION', $codes($x), true) && $x['candidates_hpp']['so_price_base'] > 13000 && $near((float) $x['candidates_hpp']['keep_system'], 9120.0));
$x = $P['Patung Naruto X'];
check('B14 condition-only text ("stok (deadstok)", no qty) on an item whose opening already equals the SO quantity: NO_CHANGE for stock, CONDITION_CORRECTION recorded, quantity NOT removed', $x['status'] === 'NO_CHANGE' && $near($x['new_qty'], 197.0) && $near($x['delta_qty'], 0.0) && $x['condition'] === 'DEADSTOCK' && in_array('CONDITION_CORRECTION', $x['types'], true));
$x = $P['Bonigrasa X'];
check('B15 "salah nama harusnya susu kental manis tiga sapi x": mapping resolved Bonigrasa → the SKM item, qty 0 explicit, but the pair must be CONFIRMED with its own correction row → BLOCKED MAPPING_COUNTERPART_REQUIRES_OWN_CORRECTION; no duplicated stock', $x['mapping']['to_sku'] === 'RM-T-SKM' && $x['mapping']['from_sku'] === 'RM-T-BON' && in_array('MAPPING_COUNTERPART_REQUIRES_OWN_CORRECTION', $codes($x), true) && $x['status'] === 'BLOCKED', json_encode($x['mapping']));
$x = $P['Red Handsoap X'];
check('B16 "di sistem ke hadwash jasmine": the item does not exist → BLOCKED MAPPING_TARGET_UNRESOLVED (never guessed)', in_array('MAPPING_TARGET_UNRESOLVED', $codes($x), true) && $x['status'] === 'BLOCKED');
$x = $P['Info Only'];
check('B17 informational text → NO_CHANGE; no Keterangan 2 → NO_CHANGE (the existing validated data stays)', $x['status'] === 'NO_CHANGE' && $P['Tanpa Keterangan']['status'] === 'NO_CHANGE' && $x['types'] === ['NO_CHANGE']);
$x = $P['Barang Tidak Ada Di Master'];
check('B18 a corrected row with no Master Barang → BLOCKED ITEM_NOT_IN_MASTER (no item is created)', $x['status'] === 'BLOCKED' && in_array('ITEM_NOT_IN_MASTER', $codes($x), true));
$s = $plan['summary'];
check('B19 summary: 18 rows with Keterangan 2 · blocked rows listed · NO write · old / corrected opening value and Δ of the non-blocked corrections consistent', $s['rows_with_keterangan2'] === 18 && $s['blocked_rows'] >= 8 && $near($s['corrected_scm_opening_value'], $s['old_scm_opening_value_all_items'] + $s['delta_value_of_non_blocked_corrections'], 0.01) && $plan['blocked'] === true, json_encode([$s['rows_with_keterangan2'], $s['blocked_rows'], $s['delta_value_of_non_blocked_corrections']]));
$rec = $P['Gula Up']['recon'];
check('B20 current reconciliation (Gula Up): corrected opening 15 + Stock IN after 1 Oct 3 = expected 18; actual 13; difference 5 = the correction delta; unexplained 0', $near($rec['in'], 3.0) && $near(15.0 + $rec['move_qty'], 18.0) && $near($rec['actual_qty'], 13.0) && $near(18.0 - $rec['actual_qty'], $P['Gula Up']['delta_qty']) && $near($P['Gula Up']['old_qty'] + $rec['move_qty'] - $rec['actual_qty'], 0.0));
$o = sc_write_outputs($plan, $wb, $tmp . '/out');
$names = array_map('basename', $o);
sort($names);
check('B21 the ten output files exist', $names === ['scm_admin_correction_summary.json', 'scm_admin_corrections_all_rows.csv', 'scm_blockers.csv', 'scm_condition_corrections.csv', 'scm_current_reconciliation.csv', 'scm_fifo_impact.csv', 'scm_hpp_corrections.csv', 'scm_mapping_corrections.csv', 'scm_qty_corrections.csv', 'scm_uom_corrections.csv'], implode(',', $names));
$all = array_map('str_getcsv', file($tmp . '/out/scm_admin_corrections_all_rows.csv', FILE_IGNORE_NEW_LINES));
$h = array_map(static fn ($c) => ltrim($c, "\xEF\xBB\xBF"), $all[0]);
check('B22 all-rows csv: every workbook row (19) with the required columns (source row, item code, item_id, name, base unit, old / corrected qty, delta, old / corrected HPP, values, delta value, condition, correction type, Keterangan 2, mapping, status, blocker)', count($all) === 20 && !array_diff(['source_row', 'source_item_code', 'master_item_id', 'item_name', 'base_unit', 'old_opening_qty', 'admin_corrected_opening_qty', 'delta_qty', 'old_opening_hpp', 'corrected_opening_hpp', 'old_opening_value', 'corrected_opening_value', 'delta_value', 'final_condition', 'correction_type', 'keterangan_2', 'mapping_from', 'mapping_to', 'status', 'blocker'], $h));
$fifoCsv = array_map('str_getcsv', file($tmp . '/out/scm_fifo_impact.csv', FILE_IGNORE_NEW_LINES));
check('B23 FIFO csv: original opening FIFO qty, consumed since 1 Oct, remaining, old / corrected unit cost, COGS consumed, impact and the classification for each corrected item', count($fifoCsv) > 5 && in_array('classification', array_map(static fn ($c) => ltrim($c, "\xEF\xBB\xBF"), $fifoCsv[0]), true) && in_array('cogs_already_consumed', array_map(static fn ($c) => ltrim($c, "\xEF\xBB\xBF"), $fifoCsv[0]), true));
$sj = json_decode((string) file_get_contents($tmp . '/out/scm_admin_correction_summary.json'), true);
check('B24 summary json carries the counts, the blocker codes, preview sha, nothing_written', $sj['nothing_written'] === true && $sj['preview_sha'] === $plan['preview_sha'] && isset($sj['blocker_codes']['REDUCTION_EXCEEDS_OPENING_LAYER']) && $sj['rows_with_keterangan2'] === 18 && isset($sj['corrected_scm_opening_value']));
$cli = static function (string $args) use ($root): array {
    $o = [];
    exec('php ' . escapeshellarg($root . '/scripts/rv3/scm_correction.php') . ' ' . $args . ' 2>&1', $o, $code);
    return [$code, implode("\n", $o)];
};
$b0 = $snap();
[$code, $o] = $cli('preview --app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($bPath) . ' --out=' . escapeshellarg($tmp . '/out2'));
check('B25 CLI preview: exit 11 (blocked), prints the blockers and the PREVIEW SHA256, wrote the ten files, changed nothing', $code === 11 && str_contains($o, 'PREVIEW SHA256') && str_contains($o, 'DIBLOKIR') && is_file($tmp . '/out2/scm_fifo_impact.csv') && $snap() === $b0, "exit {$code}");
[$code, $o] = $cli('preview --app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($bPath) . ' --out=' . escapeshellarg($root . '/out_must_not_exist'));
check('B26 the CLI refuses an --out inside the application tree (exit 3)', $code === 3 && !is_dir($root . '/out_must_not_exist'));
[$code, $o] = $cli('post --app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($bPath) . ' --preview-sha=' . $plan['preview_sha'] . ' --actor=sc_super --yes');
check('B27 post while blockers exist: refused (exit 11), nothing written', $code === 11 && $snap() === $b0, "exit {$code}: " . substr($o, -200));

echo "\n== C. approved overrides → a clean correction → post ==\n";
$ovPath = $tmp . '/overrides.csv';
$f = fopen($ovPath, 'w');
fputcsv($f, ['source_row', 'field', 'value', 'approved_by', 'note'], ',', '"', '');
$rowOf = static fn (string $name) => (int) $P[$name]['source_row'];
foreach ([
    [$rowOf('Terigu Tali X'), 'hpp_basis', 'KEEP', 'Admin SCM', 'harga sistem benar'],
    [$rowOf('Kertas Foto X'), 'unit_factor', '20', 'Admin SCM', '1 pak = 20 pcs dikonfirmasi gudang'],
    [$rowOf('Bonigrasa X'), 'mapping_confirmed', 'YES', 'Admin SCM', 'salah nama; stok milik Susu Kental Manis'],
    [$rowOf('Susu Kental Manis Tiga Sapi X'), 'mapping_confirmed', 'YES', 'Admin SCM', 'pasangan'],
    [$rowOf('Pewarna Hpp Cogs'), 'hpp_basis', 'KEEP', '', 'belum disetujui — harus diabaikan'],
] as $x) {
    fputcsv($f, $x, ',', '"', '');
}
fclose($f);
$ov = sc_load_overrides($ovPath);
check('C1 overrides: 4 approved rows (4 source rows) applied, the row without approved_by is ignored and listed', count($ov['rows']) === 4 && count($ov['ignored']) === 1 && $ov['errors'] === [] && $ov['digest'] !== '');
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
$plan2 = sc_plan($pdo, $wb, $ov);
$pdo->exec('ROLLBACK');
$pdo->exec('SET SESSION TRANSACTION READ WRITE');
$P2 = [];
foreach ($plan2['rows'] as $r) {
    $P2[$r['workbook_name']] = $r;
}
check('C2 with the approved basis KEEP, Terigu Tali X becomes READY: the ledger opening is 815 KG, the admin says 800 → DECREASE 15 at the FIFO layer cost 9.120 (value 136.800); the basis question is settled by the person, not assumed', $P2['Terigu Tali X']['status'] === 'READY' && $P2['Terigu Tali X']['action'] === 'DECREASE' && $near($P2['Terigu Tali X']['delta_qty'], -15.0) && $near($P2['Terigu Tali X']['delta_value'], -136800.0) && !in_array('HPP_BASIS_CONFIRMATION', $P2['Terigu Tali X']['blocker_codes'], true), json_encode([$P2['Terigu Tali X']['status'], $P2['Terigu Tali X']['blockers']]));
$x = $P2['Kertas Foto X'];
check('C3 "5 pak" with the confirmed unit_factor 20 → 100 PCS (= the opening): NO_CHANGE and the UOM correction recorded (no stock moves, the label is corrected)', $x['status'] === 'NO_CHANGE' && $near($x['new_qty'], 100.0) && in_array('UOM_CORRECTION', $x['types'], true), json_encode([$x['status'], $x['blockers'], $x['new_qty']]));
check('C4 mapping pair confirmed by a person AND both items have their own explicit correction rows → settled (Bonigrasa 32 → 0 KG, SKM stays 32); no duplicated stock', $P2['Bonigrasa X']['status'] === 'READY' && $P2['Bonigrasa X']['action'] === 'DECREASE' && $near($P2['Bonigrasa X']['new_qty'], 0.0) && !in_array('MAPPING_COUNTERPART_REQUIRES_OWN_CORRECTION', $P2['Bonigrasa X']['blocker_codes'], true) && $P2['Susu Kental Manis Tiga Sapi X']['status'] === 'NO_CHANGE');
check('C5 the plan still lists the blockers that no override settled: exceed, COGS, mapping target, not-in-master → still blocked, post impossible', $plan2['blocked'] && $P2['Plastik Exceed']['status'] === 'BLOCKED' && $P2['Pewarna Hpp Cogs']['status'] === 'BLOCKED' && $P2['Red Handsoap X']['status'] === 'BLOCKED');
check('C6 the preview sha changes with the overrides (digest bound)', $plan2['preview_sha'] !== $plan['preview_sha'] && $plan2['overrides']['digest'] === $ov['digest']);

// the clean correction: only the settled rows
$cleanRows = array_values(array_filter($pb, static fn ($r) => in_array($r[0], ['Gula Up', 'Dus Down', 'Terigu Zero', 'Pewarna Hpp Safe', 'Patung Baru', 'Pastricia Gr', 'Hand Glove X', 'Bonigrasa X', 'Susu Kental Manis Tiga Sapi X', 'Patung Naruto X'], true)));
$cleanScm = array_values(array_filter($scm, static fn ($r) => in_array($r[1], ['Gula Up', 'Dus Down', 'Terigu Zero', 'Pewarna Hpp Safe', 'Pastricia Gr', 'Hand Glove X', 'Susu Kental Manis Tiga Sapi X', 'Patung Naruto X'], true)));
$cPath = $tmp . '/clean.xlsx';
ExcelWriterService::write($cPath, ['Perbandingan' => ['headers' => $hdr, 'rows' => $cleanRows], 'Data SCM' => ['headers' => ['SKU', 'Nama Produk', 'Kategori', 'Satuan', 'Stok Tersedia', 'Stok Minimal', 'Status', 'Nilai Stok (Rp)', 'Key'], 'rows' => $cleanScm]]);
// the 'Patung Baru' row needs an item to exist: it is a master item without any ledger (SKU unknown to Data SCM → matched by the normalised name)
$wbC = sc_read_workbook($cPath);
$rowC = static function (string $name) use ($wbC): int {
    foreach ($wbC['rows'] as $r) {
        if ($r['name'] === $name) {
            return (int) $r['row'];
        }
    }
    return 0;
};
$ovPathC = $tmp . '/overrides_clean.csv';
$f = fopen($ovPathC, 'w');
fputcsv($f, ['source_row', 'field', 'value', 'approved_by', 'note'], ',', '"', '');
fputcsv($f, [$rowC('Bonigrasa X'), 'mapping_confirmed', 'YES', 'Admin SCM', 'salah nama; stok milik Susu Kental Manis'], ',', '"', '');
fputcsv($f, [$rowC('Susu Kental Manis Tiga Sapi X'), 'mapping_confirmed', 'YES', 'Admin SCM', 'pasangan'], ',', '"', '');
fclose($f);
$ovC = sc_load_overrides($ovPathC);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
$planC = sc_plan($pdo, $wbC, $ovC);
$pdo->exec('ROLLBACK');
$pdo->exec('SET SESSION TRANSACTION READ WRITE');
$PC = [];
foreach ($planC['rows'] as $r) {
    $PC[$r['workbook_name']] = $r;
}
check('C7 the clean workbook: no blocker, READY corrections only (Up, Down, Zero, Hpp Safe, Patung Baru, Pastricia, Hand Glove, Bonigrasa)', $planC['blocked'] === false && $planC['summary']['ready_rows'] === 8, json_encode(array_column($planC['blockers'], 'detail')));
$wantOpen = ['Gula Up' => [15.0, 15000.0], 'Dus Down' => [80.0, 4400.0], 'Terigu Zero' => [0.0, 0.0], 'Pewarna Hpp Safe' => [100.0, 5000.0], 'Patung Baru' => [40.0, 400000.0], 'Pastricia Gr' => [60000.0, 60000.0], 'Hand Glove X' => [3500.0, 239750.0], 'Bonigrasa X' => [0.0, 0.0]];
$totalDelta = 0.0;
foreach ($wantOpen as $n => [$q, $v]) {
    $totalDelta += $PC[$n]['delta_value'];
}
$clean = ($totalDelta);
$cliC = static fn (string $args) => $cli($args);
$base = '--app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($cPath) . ' --overrides=' . escapeshellarg($ovPathC);
$snapNonLedger = static function () use ($pdo): array {
    $o = [];
    foreach (['stock_opname_sessions', 'stock_opname_lines', 'stock_opname_findings', 'inventory_effective_dates'] as $t) {
        $o[$t] = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM)[1];
    }
    return $o;
};
$histBefore = $pdo->query("SELECT COUNT(*), COALESCE(SUM(id),0), MAX(id) FROM inventory_transactions")->fetch(PDO::FETCH_NUM);
$histRows = $pdo->query('SELECT id, transaction_uuid, transaction_type, transaction_date, posting_date, created_at, status, reference_no FROM inventory_transactions ORDER BY id')->fetchAll();
$auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$nonLedger = $snapNonLedger();
$s0 = V3::overview($pdo, '2026-10-01', '2026-10-31', $wh)['split_totals'];
$sep0 = V3::overview($pdo, '2026-09-01', '2026-09-30', $wh)['split_totals'];
[$code, $o] = $cli("post {$base} --preview-sha={$planC['preview_sha']} --actor=sc_super");
check('C8 post without --yes: exit 10 NOT APPLIED, nothing written', $code === 10 && str_contains($o, 'NOT APPLIED') && (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = '" . SC_REFERENCE . "'")->fetchColumn() === 0);
[$code, $o] = $cli("post {$base} --preview-sha=" . str_repeat('0', 64) . ' --actor=sc_super --yes');
check('C9 post with a sha that is not the reviewed preview: exit 13, nothing written', $code === 13 && (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = '" . SC_REFERENCE . "'")->fetchColumn() === 0, "exit {$code}");
[$code, $o] = $cli("post {$base} --preview-sha={$planC['preview_sha']} --actor=sc_admin --yes");
check('C10 post by a non-SUPERADMIN: exit 14, nothing written', $code === 14 && (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = '" . SC_REFERENCE . "'")->fetchColumn() === 0, "exit {$code}");
[$code, $o] = $cli("post {$base} --preview-sha={$planC['preview_sha']} --actor=sc_super --yes");
check('C11 post --yes: exit 0, 8 item corrections', $code === 0 && str_contains($o, 'POSTED 8 item correction'), "exit {$code}: " . substr($o, -300));
// ---- the ledger
$tx = $pdo->query("SELECT transaction_type, transaction_date, posting_date, created_at, status, reference_no FROM inventory_transactions WHERE reference_no = '" . SC_REFERENCE . "'")->fetchAll();
check('C12 every correction transaction: ADJUSTMENT-type (never IN / OUT / Transfer / OPNAME), POSTED, reference SCM_ADMIN_CORRECTION_20261001, dated 2026-09-30 23:59:59; posting time / created_at are the real posting time', count($tx) >= 9 && count(array_unique(array_column($tx, 'transaction_type'))) === 1 && $tx[0]['transaction_type'] === 'ADJUSTMENT' && count(array_unique(array_column($tx, 'transaction_date'))) === 1 && $tx[0]['transaction_date'] === SC_TX_INSTANT && substr((string) $tx[0]['posting_date'], 0, 10) === date('Y-m-d'), json_encode([count($tx), $tx[0]['transaction_date'], $tx[0]['posting_date']]));
$histAfter = $pdo->query('SELECT id, transaction_uuid, transaction_type, transaction_date, posting_date, created_at, status, reference_no FROM inventory_transactions WHERE id <= ' . (int) $histBefore[2] . ' ORDER BY id')->fetchAll();
check('C13 NOTHING historical was edited: every earlier transaction row (type, dates, posting time, status, reference) is byte-identical; Stock Opname sessions / lines / findings and the effective-date table untouched', $histAfter === $histRows && $snapNonLedger() === $nonLedger);
InventoryEffectiveDateService::resetCache();
$st = sc_load_state($pdo, 'SCM');
$okOpen = true;
$det = [];
foreach ($wantOpen as $n => [$q, $v]) {
    $l = $st['ledger'][$PC[$n]['item_id']] ?? ['open_qty' => 0.0, 'open_value' => 0.0];
    if (!$near($l['open_qty'], $q, 0.001) || !$near($l['open_value'], $v, 0.5)) {
        $okOpen = false;
        $det[] = "{$n}: {$l['open_qty']} / {$l['open_value']} want {$q} / {$v}";
    }
}
check('C14 the OPENING (ledger, reporting date < 2026-10-01) of every corrected item == the admin-corrected quantity and value', $okOpen, implode('; ', $det));
$inOct = (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions t WHERE t.reference_no = '" . SC_REFERENCE . "' AND t.transaction_date >= '2026-10-01 00:00:00'")->fetchColumn();
check('C15 no correction transaction is in October', $inOct === 0);
$s1 = V3::overview($pdo, '2026-10-01', '2026-10-31', $wh)['split_totals'];
$sep1 = V3::overview($pdo, '2026-09-01', '2026-09-30', $wh)['split_totals'];
$delta = array_sum(array_map(static fn ($n) => $PC[$n]['delta_value'], array_keys($wantOpen)));
check('C16 Reports V3 (SCM): October Stok Awal grew by exactly the correction (Δ ' . number_format($delta, 2, '.', '') . '); October IN / OUT / Transfer / Adjustment are UNCHANGED (the correction is not an October movement); Stok Akhir moved by the same Δ', $near($s1['opening'], $s0['opening'] + $delta, 0.5) && $near($s1['in'], $s0['in']) && $near($s1['out'], $s0['out']) && $near($s1['adjustment'], $s0['adjustment']) && $near($s1['closing'], $s0['closing'] + $delta, 0.5), json_encode([$s0['opening'], $s1['opening'], $delta]));
check('C17 continuity: September closing == October opening; identity (difference) 0 in both months', $near($sep1['closing'], $s1['opening'], 0.01) && $near($s1['difference'], 0.0) && $near($sep1['difference'], 0.0));
$alloc = (int) $pdo->query("SELECT COUNT(*) FROM stock_adjustments WHERE reference_no = '" . SC_REFERENCE . "' AND adjustment_type = 'CORRECTION'")->fetchColumn();
check('C18 stock_adjustments rows are type CORRECTION with the reference (never OPNAME) and the reason ADMIN_KETERANGAN_2', $alloc >= 9 && (int) $pdo->query("SELECT COUNT(*) FROM stock_adjustments WHERE reference_no = '" . SC_REFERENCE . "' AND reason LIKE 'ADMIN_KETERANGAN_2:%'")->fetchColumn() === $alloc);
$aud = json_decode((string) $pdo->query("SELECT after_data FROM audit_logs WHERE action_code = 'SCM_OPENING_CORRECTION_LINE' AND after_data LIKE '%Patung Baru%' OR after_data LIKE '%deadstok%' LIMIT 1")->fetchColumn(), true);
$hdrAudit = json_decode((string) $pdo->query("SELECT after_data FROM audit_logs WHERE action_code = 'SCM_OPENING_CORRECTION_POST'")->fetchColumn(), true);
check('C19 audit: one SCM_OPENING_CORRECTION_LINE per item (Keterangan 2 raw text, types, old / corrected qty + value, final condition, reason ADMIN_KETERANGAN_2, transaction ids) + one header (workbook sha, preview sha, totals)', (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'SCM_OPENING_CORRECTION_LINE'")->fetchColumn() === 8 && ($aud['reason'] ?? '') === 'ADMIN_KETERANGAN_2' && isset($aud['keterangan_2'], $aud['final_condition'], $aud['old_qty'], $aud['corrected_qty'], $aud['transaction_ids']) && ($hdrAudit['items'] ?? 0) === 8 && ($hdrAudit['workbook_sha256'] ?? '') === $wbC['sha256']);
$deadAudit = null;
foreach ($pdo->query("SELECT after_data FROM audit_logs WHERE action_code = 'SCM_OPENING_CORRECTION_LINE'")->fetchAll(PDO::FETCH_COLUMN) as $j) {
    $d = json_decode((string) $j, true);
    if (($d['sku'] ?? '') === 'RM-T-NEW') {
        $deadAudit = $d;
    }
}
check('C20 the physical condition is kept in the correction audit (DEADSTOCK) and the quantity is NOT removed from stock (40 PCS on hand)', ($deadAudit['final_condition'] ?? '') === 'DEADSTOCK' && $near(\App\Services\InventoryService::currentStock($pdo, $PC['Patung Baru']['item_id'], $wh)['qty_base'], 40.0));
// ---- current reconciliation after the posting
$st2 = sc_load_state($pdo, 'SCM');
$diff = [];
foreach ($wantOpen as $n => [$q, $v]) {
    $l = $st2['ledger'][$PC[$n]['item_id']];
    $cur = $st2['current'][$PC[$n]['item_id']]['qty'] ?? 0.0;
    $mv = $l['m_in'] + $l['m_out'] + $l['m_tin'] + $l['m_tout'] + $l['m_adj'];
    if (!$near($cur, $q + $mv, 0.001)) {
        $diff[] = "{$n}: current {$cur} vs {$q} + {$mv}";
    }
}
check('C21 current reconciliation after the posting: corrected opening + post-opening movements == actual on-hand for every corrected item (difference 0); Gula Up 15 + 3 (October IN) = 18', $diff === [] && $near(\App\Services\InventoryService::currentStock($pdo, $PC['Gula Up']['item_id'], $wh)['qty_base'], 18.0), implode('; ', $diff));
$fifoOk = (int) $pdo->query("SELECT COUNT(*) FROM inventory_batches WHERE warehouse_id = {$wh} AND qty_base < 0")->fetchColumn() === 0;
check('C22 no negative FIFO layer was created; the consumed October layers of the other items are untouched', $fifoOk && $near((float) $pdo->query("SELECT qty_base FROM inventory_batches b JOIN inventory_transaction_lines l ON l.id = b.source_transaction_line_id JOIN inventory_transactions t ON t.id = l.transaction_id WHERE t.transaction_uuid = 'T-EX-1'")->fetchColumn(), 10.0));
[$code, $o] = $cli("verify --app-root=" . escapeshellarg($root));
check('C23 CLI verify: exit 0, VERIFY OK (opening == corrected, nothing in October, ADJUSTMENT types, no OPNAME, current == opening + movements, V3 continuity)', $code === 0 && str_contains($o, 'VERIFY OK') && !str_contains($o, 'FAIL -'), $o);
$auditBeforeSecond = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$txBeforeSecond = (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
[$code, $o] = $cli("post {$base} --preview-sha={$planC['preview_sha']} --actor=sc_super --yes");
check('C24 second execution: SCM_OPENING_CORRECTION_ALREADY_POSTED (exit 12), nothing written, no duplicate', $code === 12 && str_contains($o, 'SCM_OPENING_CORRECTION_ALREADY_POSTED') && (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn() === $txBeforeSecond && (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === $auditBeforeSecond, "exit {$code}: " . substr($o, -200));
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
$planAfter = sc_plan($pdo, $wbC, $ovC);
$pdo->exec('ROLLBACK');
$pdo->exec('SET SESSION TRANSACTION READ WRITE');
check('C25 a new preview after the posting reports the correction as already posted (blocked, SCM_OPENING_CORRECTION_ALREADY_POSTED) and every corrected row now has delta 0', $planAfter['blocked'] && str_contains(json_encode($planAfter['blockers']), 'SCM_OPENING_CORRECTION_ALREADY_POSTED') && count(array_filter($planAfter['rows'], static fn ($r) => $r['ket2'] !== '' && $r['delta_qty'] !== null && abs($r['delta_qty']) > 0.0005)) === 0);

echo "\n== D. admin clarification — PEWARNA CROSS ORANGE @60 ML (opening 240 ml / Rp120.000, HPP Rp500/ml, nothing consumed) ==\n";
$ml = $unit['ML'];
$orange = $mk('RM-T-ORANGE', 'Pewarna Cross Orange @60 ML', 'ML');
$in($orange, 'ML', 300, 500, '2026-09-20 09:00:00', 'T-OR-1');                                  // the system opening: 300 ml @ Rp500 = Rp150.000
$out($orange, 'ML', 60, '2026-10-05 09:00:00', 'T-OR-OUT');                                      // 60 ml leave the layer in October → current 240
$fill = static fn (int $i) => ['Filler ' . $i, 'Pcs', 1, 1, 0, 1, 1, 0, 'Sama', sc_key('Filler ' . $i), 'PCS', 1, ''];
$dRows = [$fill(1), $fill(2), $fill(3), $fill(4), $fill(5),                                      // rows 2..6 → the Orange row is row 7, exactly as in the real workbook
    ['PEWARNA CROSS ORANGE @60 ML', 'Pcs', 240, 240, 0, 7200000, 120000, -7080000, 'Selisih nominal', sc_key('PEWARNA CROSS ORANGE @60 ML'), 'ML', 1, 'nominal yang betul 120.000']];
$dPath = $tmp . '/orange.xlsx';
ExcelWriterService::write($dPath, ['Perbandingan' => ['headers' => $hdr, 'rows' => $dRows], 'Data SCM' => ['headers' => ['SKU', 'Nama Produk', 'Kategori', 'Satuan', 'Stok Tersedia', 'Stok Minimal', 'Status', 'Nilai Stok (Rp)', 'Key'], 'rows' => [['RM-T-ORANGE', 'PEWARNA CROSS ORANGE @60 ML', 'Premix', 'ML', 240, 0, 'AMAN', 120000, sc_key('PEWARNA CROSS ORANGE @60 ML')]]]]);
$wbD = sc_read_workbook($dPath);
$planOf = static function (array $w, array $o) use ($pdo): array {
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION');
    $p = sc_plan($pdo, $w, $o);
    $pdo->exec('ROLLBACK');
    $pdo->exec('SET SESSION TRANSACTION READ WRITE');
    return $p;
};
$byName = static function (array $p, string $n): array {
    foreach ($p['rows'] as $r) {
        if ($r['workbook_name'] === $n) {
            return $r;
        }
    }
    return [];
};
$pD0 = $planOf($wbD, sc_load_overrides(null));
$x = $byName($pD0, 'PEWARNA CROSS ORANGE @60 ML');
check('D1 the ledger really is the situation the admin corrects: opening 300 ml / Rp150.000 @500, 60 ml consumed since 1 Oct, current 240', $near($x['old_qty'], 300.0) && $near($x['old_value'], 150000.0) && $near((float) $x['old_unit_cost'], 500.0) && $near($x['recon']['actual_qty'], 240.0) && $near($x['recon']['out'], -60.0));
check('D2 WITHOUT the admin clarification the row is never a COGS restatement: the workbook unit (Pcs) vs the SCM unit (ML) with factor 1 is not trusted → BLOCKED UNIT_DIMENSION_MISMATCH', $x['status'] === 'BLOCKED' && in_array('UNIT_DIMENSION_MISMATCH', $x['blocker_codes'], true) && !in_array('REQUIRES_COGS_RESTATEMENT', $x['blocker_codes'], true), json_encode($x['blocker_codes']));
$ovD = sc_load_overrides($root . '/tests/fixtures/scm/scm_admin_overrides.csv');
check('D3 the packaged admin clarification file loads: row 7 final_qty_base 240 + final_value 120000, approved by the admin, no error', isset($ovD['rows'][7]['final_qty_base']) && $ovD['rows'][7]['final_qty_base'] === '240' && $ovD['rows'][7]['final_value'] === '120000' && $ovD['errors'] === [] && $ovD['digest'] !== '');
$pD = $planOf($wbD, $ovD);
$x = $byName($pD, 'PEWARNA CROSS ORANGE @60 ML');
check('D4 with the clarification: opening qty 240 ml is authoritative, HPP stays Rp500/ml, value Rp120.000; delta −60 ml / −Rp30.000; READY, DECREASE', $x['status'] === 'READY' && $near($x['new_qty'], 240.0) && $near((float) $x['new_unit_cost'], 500.0, 0.0001) && $near($x['new_value'], 120000.0) && $near($x['delta_qty'], -60.0) && $near($x['delta_value'], -30000.0) && $x['action'] === 'DECREASE', json_encode([$x['status'], $x['blockers'], $x['new_qty'], $x['new_value']]));
check('D5 classification QTY_CORRECTION only (not HPP, not UOM, not condition): from system opening 300 ml to admin opening 240 ml', $x['types'] === ['QTY_CORRECTION'], json_encode($x['types']));
check('D6 FIFO: SAFE_QTY_CORRECTION — no COGS restatement, no consumed-layer correction (cost unchanged 500 → 500; the 60 ml reduction fits the remaining opening layer of 240)', $x['fifo_class'] === 'SAFE_QTY_CORRECTION' && $x['fifo']['cost_differs'] === false && !isset($x['fifo']['cogs_restatement']) && !in_array('REQUIRES_COGS_RESTATEMENT', $x['blocker_codes'], true) && $near($x['fifo']['remaining_open'], 240.0), json_encode($x['fifo']));
check('D7 the planned value delta equals the FIFO value the reduction removes: 60 × 500 = Rp30.000 (the layer is reduced from the remaining stock, nothing consumed is edited)', $near(sc_simulate_decrease(sc_fifo_facts($pdo, sc_load_state($pdo, 'SCM'), [$x['item_id']])[$x['item_id']], 60.0)['value'], 30000.0));
check('D8 the correction belongs to the opening of 2026-10-01: transaction instant 2026-09-30 23:59:59 (Stok Akhir September = Stok Awal Oktober), never an October movement', SC_TX_INSTANT === '2026-09-30 23:59:59' && SC_EFFECTIVE_DATE === '2026-10-01');
$rd = $x['recon'];
check('D9 reconciliation is shown honestly: before the correction actual 240 = old opening 300 + movement −60 (unexplained 0); after it the expected current is 180, difference −60 = the correction delta', $near($rd['actual_qty'] - ($x['old_qty'] + $rd['move_qty']), 0.0) && $near($x['new_qty'] + $rd['move_qty'], 180.0) && $near(($x['new_qty'] + $rd['move_qty']) - $rd['actual_qty'], $x['delta_qty']));

$pWrong = $planOf($wb, $ovD);
check('D10 the clarification file refuses to apply to another workbook (row 7 of that workbook is a different item) → global blocker, not a silent mis-application', str_contains(json_encode($pWrong['blockers']), 'file overrides dibuat untuk workbook lain'));

$ok = count(array_filter($results));
echo "\n{$ok} / " . count($results) . " PASSED\n";
exit($ok === count($results) ? 0 : 1);
