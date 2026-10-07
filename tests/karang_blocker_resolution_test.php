<?php
declare(strict_types=1);

/**
 * Karang Tengah opening — BLOCKER RESOLUTION (scripts/rv3/kt_resolve_lib.php, kt_blocker_resolution.php, the --resolutions option of kt_opening.php).
 *
 * A production-like master (items, units, conversions, history, an earlier approved SO mapping) and a synthetic workbook that reproduces every blocker kind of the production preview:
 * NOT_FOUND (existing item under another code / name variant / truly new / ambiguous / pack-size conflict / duplicate candidate) and UNIT_UNRESOLVED (no evidence / one value from hard
 * evidence / conflicting evidence / name hint only / hint contradicting evidence / standard physical scale). Proves: classification, no guessing, nothing created or changed in the
 * master, the five output files, the approved-resolutions mechanism (only approved rows are applied, invalid / unused / overriding rows are blockers, the digest binds the post),
 * value preservation, and a clean preview (exit 0) once everything is resolved.
 *
 * Usage: php tests/karang_blocker_resolution_test.php
 */

foreach (glob(__DIR__ . '/../services/*.php') as $f) {
    if (basename($f) !== 'ReportsV3Routes.php') {
        require_once $f;
    }
}
require_once __DIR__ . '/../scripts/rv3/kt_opening_lib.php';
require_once __DIR__ . '/../scripts/rv3/kt_resolve_lib.php';

use App\Services\Database;
use App\Services\ExcelWriterService;
use App\Services\FifoService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
$near = static fn (float $a, float $b, float $e = 0.01): bool => abs($a - $b) <= $e;
$pdo = Database::connection();
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/kt_res_' . bin2hex(random_bytes(4));
mkdir($tmp);

$unit = [];
foreach ($pdo->query('SELECT id, code FROM units')->fetchAll() as $u) {
    $unit[$u['code']] = (int) $u['id'];
}
$role = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES ('kr_super','x','kr_super',:r,1)")->execute(['r' => $role]);
$superId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('KARANG_TENGAH', 'Gudang Karang Tengah', 'TRANSIT', 1, 1)");
$pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active) VALUES ('SCM_X', 'SCM X', 'MAIN', 1)");
$scm = (int) $pdo->query("SELECT id FROM warehouses WHERE code='SCM_X'")->fetchColumn();
$pdo->exec("INSERT INTO categories (code, name) VALUES ('PEWARNA','Pewarna'), ('SUSU','Susu & Dairy'), ('BAHAN','Bahan Baku')");
$cat = [];
foreach ($pdo->query('SELECT id, code FROM categories')->fetchAll() as $c) {
    $cat[$c['code']] = (int) $c['id'];
}
$mk = static function (string $sku, string $name, string $baseUnit, ?string $category = null, string $status = 'ACTIVE') use ($pdo, $unit, $cat): int {
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, status) VALUES (:s, :n, :u, :c, :st)')->execute(['s' => $sku, 'n' => $name, 'u' => $unit[$baseUnit], 'c' => $category ? $cat[$category] : null, 'st' => $status]);
    return (int) $pdo->lastInsertId();
};
$conv = static function (int $item, string $u, float $f, string $from, ?string $to) use ($pdo, $unit): void {
    $pdo->prepare('INSERT INTO item_unit_conversions (item_id, unit_id, conversion_to_base, valid_from, valid_to) VALUES (:i, :u, :f, :a, :b)')->execute(['i' => $item, 'u' => $unit[$u], 'f' => $f, 'a' => $from, 'b' => $to]);
};

// ---------------------------------------------------------------- the production-like master
$ctl = $mk('CTL001', 'GULA PASIR CONTROL', 'KG');                                       // exact code match (control row)
$ey60 = $mk('PWN-EY-60', 'PEWARNA CROSS EGG YELLOW @60ML', 'ML', 'PEWARNA');            // A: same name, other code
$rb60 = $mk('PWN-RB-60', 'PEWARNA CROSS ROYAL BLUE @60ML', 'ML', 'PEWARNA');            // D: pack size conflict with the source @450ML
$susu = $mk('0120118', 'SUSU VAMOLA UHT', 'ML', 'SUSU');                                 // B: same code once formatting is ignored
$aqua = $mk('AQ-220', 'AQUA GELAS 220ML', 'PCS');                                        // A: earlier approved SO mapping
$pdo->prepare("INSERT INTO stock_opname_reference_item_mappings (source_code, item_id, approved_by, notes) VALUES ('201004', :i, :u, 'approved earlier')")->execute(['i' => $aqua, 'u' => $superId]);
$tp1 = $mk('TRG-BIRU', 'TEPUNG TERIGU SEGITIGA BIRU', 'KG', 'BAHAN');                    // D: two plausible candidates
$tp2 = $mk('TRG-MERAH', 'TEPUNG TERIGU SEGITIGA MERAH', 'KG', 'BAHAN');
$mg = $mk('MG-1', 'MINYAK GORENG BIMOLI', 'LTR', 'BAHAN');                               // D: proposed for two source codes
$choc = $mk('100313', 'COKLAT BUBUK DANISH GRADE A', 'KG', 'BAHAN');                    // UNIT_UNRESOLVED, no evidence
$ur1 = $mk('UR1', 'GULA HALUS', 'KG', 'BAHAN');                                          // UNIT: expired conversion + historical IN line → one value, two evidence kinds
$conv($ur1, 'PCS', 0.5, '2025-01-01 00:00:00', '2026-01-01 00:00:00');
FifoService::postIn($pdo, ['transaction_uuid' => 'KR-HIST-1', 'item_id' => $ur1, 'warehouse_id' => $scm, 'input_qty' => 2, 'input_unit_id' => $unit['PCS'], 'unit_price_input' => 12000, 'transaction_type' => 'IN', 'transaction_date' => '2025-06-01 09:00:00', 'created_by' => $superId, 'anomaly_approved_by' => $superId]);
$uc1 = $mk('UC1', 'KEJU PARUT', 'KG', 'BAHAN');                                          // UNIT: two versions with different factors → conflict
$conv($uc1, 'PCS', 0.5, '2025-01-01 00:00:00', '2025-06-01 00:00:00');
$conv($uc1, 'PCS', 0.25, '2025-06-01 00:00:00', '2026-01-01 00:00:00');
$h1 = $mk('HINT1', 'SELAI NANAS @500GR', 'KG', 'BAHAN');                                // UNIT: evidence says 2 but the name implies 0.5 → conflict
$conv($h1, 'PCS', 2.0, '2025-01-01 00:00:00', '2026-01-01 00:00:00');
$h2 = $mk('HINT2', 'SELAI COKLAT @500GR', 'KG', 'BAHAN');                               // UNIT: name hint only → still blocked
$ph = $mk('PHYS1', 'GARAM HALUS', 'KG', 'BAHAN');                                        // UNIT: Gram vs KG, standard physical scale
$pdo->exec("INSERT INTO items (sku, name, base_unit_id, status) VALUES ('INACT9','BAHAN LAMA NONAKTIF',{$unit['PCS']},'INACTIVE')");
$inact = (int) $pdo->query("SELECT id FROM items WHERE sku='INACT9'")->fetchColumn();
$pdo->prepare('INSERT INTO inventory_transaction_lines (transaction_id, line_no, item_id, item_name_snapshot, input_qty, input_unit_id, conversion_factor_snapshot, base_qty, unit_price_input, unit_cost_base, subtotal, warehouse_id)
               SELECT t.id, 9, :i, :n, 1, :u, 1, 1, 1, 1, 1, t.warehouse_id FROM inventory_transactions t WHERE t.transaction_uuid = :t')->execute(['i' => $inact, 'n' => 'KARET GELANG LEGACY', 'u' => $unit['PCS'], 't' => 'KR-HIST-1']);

// ---------------------------------------------------------------- the synthetic workbook (the production preview's blocker kinds)
$mkXlsx = static function (string $name, array $dataRows, ?float $declared) use ($tmp): string {
    $hdr = ['NO', 'ITEM', 'kode barang', 'UoM', 'HARGA', 'Stock Akhir Produksi', 'Stock Akhir Gudang', 'Jumlah '];
    $p = $tmp . "/{$name}.xlsx";
    ExcelWriterService::write($p, ['Sheet1' => ['headers' => ['', '', '', '', '', '', '', $declared], 'rows' => array_merge([$hdr], $dataRows)]]);
    return $p;
};
$n = 0;
$row = static function (string $code, string $name, string $uom, float $price, float $qty) use (&$n): array {
    return [++$n, $name, $code, $uom, $price, 0, $qty, ''];
};
$rowsBlocked = [
    $row('CTL001', 'GULA PASIR CONTROL', 'Kg', 14000, 10),
    $row('900251', 'PEWARNA CROSS EGG YELLOW @60ML', 'Ml', 616.6666667, 900),                // A (name exact)
    $row('900245', 'PEWARNA CROSS  ROYAL BLUE @450ML', 'Kg', 219780, 1),                      // D (size conflict with @60ML)
    $row('120118', 'SUSU VAMOLA', 'Ml', 500, 240),                                            // B (code normal)
    $row('201004', 'AIR MINERAL 220 ML', 'Gram', 123.4042553, 470),                           // A (earlier approved mapping) + unit blocked + sanity
    $row('100430', 'ELMER CHOCO CHIP', 'Pcs', 11358.33333, 1),                                // C (new)
    $row('444303', 'GAS PORTABLE', 'Pcs', 916.6666667, 11),                                    // C (new)
    $row('NEW2', 'BOTOL KOSONG 500 ML', 'Gram', 800, 12),                                     // C (new) but unit sanity → not ready
    $row('AMB1', 'TEPUNG TERIGU SEGITIGA', 'Kg', 11000, 5),                                   // D (two candidates)
    $row('DUP1', 'MINYAK GORENG BIMOLI', 'Ltr', 17000, 3),                                    // D (candidate also proposed for DUP2)
    $row('DUP2', 'MINYAK GORENG BIMOLI', 'Ltr', 17000, 2),
    $row('100313', 'COKLAT BUBUK DANISH GRADE A', 'Pcs', 11333.33333, 7),                     // UNIT_UNRESOLVED — no evidence
    $row('UR1', 'GULA HALUS', 'Pcs', 25000, 4),                                               // UNIT_UNRESOLVED — resolved (2 evidence kinds)
    $row('UC1', 'KEJU PARUT', 'Pcs', 30000, 2),                                               // UNIT_UNRESOLVED — conflict
    $row('HINT1', 'SELAI NANAS @500GR', 'Pcs', 20000, 3),                                      // UNIT_UNRESOLVED — hint contradicts
    $row('HINT2', 'SELAI COKLAT @500GR', 'Pcs', 20000, 3),                                     // UNIT_UNRESOLVED — hint only
    $row('PHYS1', 'GARAM HALUS', 'Gram', 10, 3000),                                           // UNIT_UNRESOLVED — standard physical scale
    $row('NEW3', 'KARET GELANG LEGACY', 'Pcs', 100, 10),                                       // A via historical transaction name… of an INACTIVE master
];
$sum = 0.0;
foreach ($rowsBlocked as $r) {
    $sum += $r[4] * $r[6];
}
$src = kt_read_source($mkXlsx('blocked', $rowsBlocked, $sum));
$tables = ['items', 'units', 'categories', 'suppliers', 'item_unit_conversions', 'item_barcodes', 'inventory_transactions', 'inventory_transaction_lines', 'inventory_batches', 'stock_opname_reference_item_mappings', 'stock_opname_sessions', 'audit_logs'];
$snap = static function () use ($pdo, $tables): array {
    $o = [];
    foreach ($tables as $t) {
        $o[$t] = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM)[1];
    }
    return $o;
};

echo "== A. baseline preview (the production situation) ==\n";
$before = $snap();
$plan = kt_plan($pdo, $src);
$by = static fn (array $p, string $code) => array_values(array_filter($p['rows'], static fn ($r) => $r['source_code'] === $code))[0];
check('A1 baseline: NOT_FOUND rows = 900251 900245 120118 201004 100430 444303 NEW2 AMB1 DUP1 DUP2 NEW3; UNIT_UNRESOLVED = 100313 UC1 HINT1 HINT2 PHYS1 UR1',
    array_column(array_filter($plan['rows'], static fn ($r) => $r['status'] === 'NOT_FOUND'), 'source_code') === ['900251', '900245', '120118', '201004', '100430', '444303', 'NEW2', 'AMB1', 'DUP1', 'DUP2', 'NEW3']
    && array_column(array_filter($plan['rows'], static fn ($r) => $r['status'] === 'UNIT_UNRESOLVED'), 'source_code') === ['100313', 'UR1', 'UC1', 'HINT1', 'HINT2', 'PHYS1'], json_encode($plan['by_status']));
check('A2 preview is blocked and its sha has no resolution digest', $plan['blocked'] && $plan['resolutions']['digest'] === '' && $plan['resolutions']['file'] === null);
$before = $snap();

echo "\n== B. the read-only analysis ==\n";
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
$r = kr_resolve($pdo, $plan, $src);
$pdo->exec('ROLLBACK');
$pdo->exec('SET SESSION TRANSACTION READ WRITE');
check('B0 the analysis changed NOTHING in the master / ledger / audit (checksums identical; no item created)', $snap() === $before);
$nf = [];
foreach ($r['not_found'] as $x) {
    $nf[$x['row']['source_code']] = $x;
}
check('B1 900251 PEWARNA CROSS EGG YELLOW @60ML → A EXISTING_ITEM_WRONG_SOURCE_CODE → PWN-EY-60 (exact normalised name, same pack size), HIGH', $nf['900251']['c']['class'] === 'EXISTING_ITEM_WRONG_SOURCE_CODE' && $nf['900251']['c']['proposal']['item_id'] === $ey60 && $nf['900251']['c']['confidence'] === 'HIGH', json_encode($nf['900251']['c']['reason']));
check('B2 900245 ROYAL BLUE @450ML is NOT mapped to the @60ML item (pack-size conflict) → D AMBIGUOUS', $nf['900245']['c']['class'] === 'AMBIGUOUS' && $nf['900245']['c']['proposal'] === null && str_contains($nf['900245']['c']['reason'], 'ukuran kemasan'), $nf['900245']['c']['reason']);
check('B3 120118 SUSU VAMOLA → B EXISTING_ITEM_NAME_VARIANT → 0120118 (code equal once formatting is ignored; the name differs)', $nf['120118']['c']['class'] === 'EXISTING_ITEM_NAME_VARIANT' && $nf['120118']['c']['proposal']['item_id'] === $susu && in_array('KODE_NORMAL_SAMA', $nf['120118']['c']['proposal']['evidence'], true), json_encode($nf['120118']['c']['reason']));
check('B4 201004 AIR MINERAL 220 ML → A via the earlier APPROVED SO mapping (stock_opname_reference_item_mappings) → AQ-220', $nf['201004']['c']['class'] === 'EXISTING_ITEM_WRONG_SOURCE_CODE' && $nf['201004']['c']['proposal']['item_id'] === $aqua && str_contains(implode(',', $nf['201004']['c']['proposal']['evidence']), 'PEMETAAN_TERDAHULU'));
check('B5 201004: the unit would NOT resolve after that mapping (Gram vs PCS) and the name / unit are inconsistent (220 ML counted in Gram) — both reported', str_contains($nf['201004']['unit_check'], 'UNIT_UNRESOLVED setelah pemetaan') && str_contains($nf['201004']['unit_sanity'], 'UOM_NAMA_TIDAK_SEJALAN'), $nf['201004']['unit_check'] . ' / ' . $nf['201004']['unit_sanity']);
check('B6 100430 ELMER CHOCO CHIP and 444303 GAS PORTABLE → C TRULY_NEW_MASTER (nothing plausible in the master)', $nf['100430']['c']['class'] === 'TRULY_NEW_MASTER' && $nf['444303']['c']['class'] === 'TRULY_NEW_MASTER');
check('B7 AMB1 (two plausible tepung items) → D AMBIGUOUS, both candidates listed, nothing mapped by name similarity', $nf['AMB1']['c']['class'] === 'AMBIGUOUS' && $nf['AMB1']['c']['proposal'] === null && count($nf['AMB1']['c']['candidates']) >= 2, $nf['AMB1']['c']['reason']);
check('B8 DUP1 and DUP2 resolve to the same master item MG-1 → both D AMBIGUOUS (one item cannot be mapped twice)', $nf['DUP1']['c']['class'] === 'AMBIGUOUS' && $nf['DUP2']['c']['class'] === 'AMBIGUOUS' && str_contains($nf['DUP1']['c']['reason'], 'lebih dari satu kode sumber'));
check('B9 NEW3 matches the HISTORICAL transaction name of an INACTIVE master item → classified A, but the proposal is withheld (INACTIVE master is never reactivated here)', $nf['NEW3']['c']['class'] === 'EXISTING_ITEM_WRONG_SOURCE_CODE' && str_contains($nf['NEW3']['unit_check'], 'INACTIVE')
    && count(array_filter($r['proposals'], static fn ($p) => $p['kind'] === 'MAP_ITEM' && $p['source_code'] === 'NEW3')) === 0, $nf['NEW3']['c']['reason'] . ' | ' . $nf['NEW3']['unit_check']);
$c = $r['counts'];
check('B10 counts: NOT_FOUND 11 → mapped existing 4 (A 3 · B 1) · truly new 3 · ambiguous 4', $c['not_found_total'] === 11 && $c['mapped_existing'] === 4 && $c['mapped_wrong_code'] === 3 && $c['mapped_name_variant'] === 1 && $c['truly_new'] === 3 && $c['ambiguous'] === 4, json_encode($c));

$u = [];
foreach ($r['units'] as $x) {
    $u[$x['source_code'] . ($x['origin'] === 'AFTER_PROPOSED_MAPPING' ? '*' : '')] = $x;
}
check('B11 100313 COKLAT BUBUK (Pcs → KG): no conversion, no history, no approved candidate → BLOCKED, conversion NOT guessed', $u['100313']['verdict'] === 'BLOCKED' && $u['100313']['proposed_factor'] === null && str_contains($u['100313']['reason'], 'tidak ada bukti'), $u['100313']['reason']);
check('B12 UR1: expired version (1 PCS = 0.5 KG) + the historical Stock IN line agree on ONE value → RESOLVED_PROPOSED 0.5, HIGH (two evidence kinds); value preserved', $u['UR1']['verdict'] === 'RESOLVED_PROPOSED' && $near((float) $u['UR1']['proposed_factor'], 0.5, 1e-9) && $u['UR1']['confidence'] === 'HIGH'
    && $near((float) $u['UR1']['norm_qty'], 2.0) && $near((float) $u['UR1']['norm_hpp'], 50000.0) && abs((float) $u['UR1']['value_difference']) <= 0.5 && str_contains($u['UR1']['history'], '1 Pcs'), json_encode([$u['UR1']['basis'], $u['UR1']['confidence'], $u['UR1']['history']]));
check('B13 UC1: two versions with different factors (0.5 vs 0.25) → BLOCKED, conflicting evidence', $u['UC1']['verdict'] === 'BLOCKED' && str_contains($u['UC1']['reason'], 'bertentangan'), $u['UC1']['reason']);
check('B14 HINT1: hard evidence says 2 but the name @500GR implies 0.5 → BLOCKED (conflict), the hint is shown', $u['HINT1']['verdict'] === 'BLOCKED' && str_contains($u['HINT1']['reason'], 'konflik') && $u['HINT1']['name_hint'] !== '', $u['HINT1']['reason']);
check('B15 HINT2: only the name says @500GR → BLOCKED (a name hint alone is never enough), hint shown, no factor', $u['HINT2']['verdict'] === 'BLOCKED' && $u['HINT2']['proposed_factor'] === null && str_contains($u['HINT2']['name_hint'], 'petunjuk'), $u['HINT2']['name_hint']);
check('B16 PHYS1: Gram vs KG with no master conversion → standard physical scale 0.001 (same dimension) → RESOLVED_PROPOSED, MEDIUM; value preserved', $u['PHYS1']['verdict'] === 'RESOLVED_PROPOSED' && $near((float) $u['PHYS1']['proposed_factor'], 0.001, 1e-12) && $u['PHYS1']['confidence'] === 'MEDIUM' && abs((float) $u['PHYS1']['value_difference']) <= 0.5 && str_contains($u['PHYS1']['basis'], 'fisik'), json_encode([$u['PHYS1']['basis'], $u['PHYS1']['norm_qty'], $u['PHYS1']['norm_hpp']]));
check('B17 201004 after the proposed mapping: Gram vs PCS (different dimensions) → BLOCKED (no physical shortcut weight ↔ count)', isset($u['201004*']) && $u['201004*']['verdict'] === 'BLOCKED');
check('B18 counts: UNIT_UNRESOLVED 6 → resolved 2 (UR1, PHYS1) · still blocked 4; after the proposed mapping 1 more unit row (blocked)', $c['unit_unresolved_total'] === 6 && $c['unit_resolved'] === 2 && $c['unit_still_blocked'] === 4 && $c['unit_after_mapping_total'] >= 1 && $c['unit_after_mapping_blocked'] >= 1, json_encode($c));
$nm = [];
foreach ($r['new_master'] as $x) {
    $nm[$x['code']] = $x;
}
check('B19 master creation PLAN (nothing created): 100430 / 444303 ready (base unit Pcs = PCS, ACTIVE, HPP = source, reference KARANG_TENGAH_SO_20261001, no supplier, no category invented)', $nm['100430']['ready_for_creation'] === 'YES' && $nm['444303']['ready_for_creation'] === 'YES' && $nm['100430']['base_unit_code'] === 'PCS' && $nm['100430']['active_status'] === 'ACTIVE'
    && $near((float) $nm['100430']['initial_hpp'], 11358.33333, 1e-6) && $nm['100430']['opening_source_reference'] === KT_REFERENCE && $nm['100430']['default_supplier'] === '' && $nm['100430']['category_id'] === '');
check('B20 NEW2 BOTOL KOSONG 500 ML counted in Gram → NOT ready (unit inconsistent with the name, needs business confirmation)', $nm['NEW2']['ready_for_creation'] === 'NO' && str_contains($nm['NEW2']['not_ready_reason'], 'UOM_NAMA_TIDAK_SEJALAN'));
check('B21 proposals csv rows exist only for A/B (MAP_ITEM) and evidence-backed units (UNIT_FACTOR), every approved_by EMPTY', count(array_filter($r['proposals'], static fn ($p) => $p['kind'] === 'MAP_ITEM')) === 3 && count(array_filter($r['proposals'], static fn ($p) => $p['kind'] === 'UNIT_FACTOR')) === 2 && count(array_filter($r['proposals'], static fn ($p) => $p['approved_by'] !== '')) === 0, json_encode(array_column($r['proposals'], 'source_code')));

echo "\n== C. output files + CLI ==\n";
$files = kr_write_outputs($r, $plan, $tmp . '/out');
$names = array_map('basename', $files);
sort($names);
check('C1 the five required files + the proposed resolutions csv are written', $names === ['ambiguous_items.csv', 'blocker_resolution_summary.json', 'karang_resolutions_PROPOSED.csv', 'not_found_resolution.csv', 'proposed_new_master.csv', 'unit_resolution.csv'], implode(',', $names));
$sumJ = json_decode((string) file_get_contents($tmp . '/out/blocker_resolution_summary.json'), true);
check('C2 summary json: NOT_FOUND 11 → mapped 4 / new 3 / ambiguous 4; UNIT_UNRESOLVED 6 → resolved 2 / blocked 4; nothing_written; path to a clean preview', $sumJ['not_found']['total'] === 11 && $sumJ['not_found']['mapped_existing'] === 4 && $sumJ['not_found']['truly_new'] === 3 && $sumJ['not_found']['ambiguous'] === 4
    && $sumJ['unit_unresolved']['total'] === 6 && $sumJ['unit_unresolved']['resolved_proposed'] === 2 && $sumJ['unit_unresolved']['still_blocked'] === 4 && $sumJ['nothing_written'] === true && isset($sumJ['path_to_clean_preview']['4_preview']));
$nfCsv = array_map('str_getcsv', file($tmp . '/out/not_found_resolution.csv', FILE_IGNORE_NEW_LINES));
$hdr = array_map(static fn ($h) => ltrim($h, "\xEF\xBB\xBF"), $nfCsv[0]);
check('C3 not_found_resolution.csv: 11 rows with source code / name / UoM / qty / HPP / candidate item_id / master code / master name / master base unit / confidence-reason', count($nfCsv) === 12 && !array_diff(['source_code', 'source_name', 'source_uom', 'source_qty', 'source_hpp', 'candidate_item_id', 'candidate_master_code', 'candidate_master_name', 'master_base_unit', 'confidence', 'reason', 'classification'], $hdr));
$amb = array_map('str_getcsv', file($tmp . '/out/ambiguous_items.csv', FILE_IGNORE_NEW_LINES));
check('C4 ambiguous_items.csv lists every candidate of the ambiguous rows (AMB1 has ≥ 2 candidate lines)', count(array_filter($amb, static fn ($l) => ($l[1] ?? '') === 'AMB1')) >= 2);

$cli = static function (string $script, string $args) use ($root): array {
    $o = [];
    exec('php ' . escapeshellarg($root . '/scripts/rv3/' . $script) . ' ' . $args . ' 2>&1', $o, $code);
    return [$code, implode("\n", $o)];
};
$before = $snap();
[$code, $o] = $cli('kt_blocker_resolution.php', '--app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($tmp . '/blocked.xlsx') . ' --out=' . escapeshellarg($tmp . '/out2'));
check('C5 CLI: exit 0, prints NOT_FOUND / UNIT_UNRESOLVED counts and "NOTHING WAS CREATED", master unchanged', $code === 0 && str_contains($o, 'NOT_FOUND total') && str_contains($o, 'mapped existing') && str_contains($o, 'truly new') && str_contains($o, 'ambiguous') && str_contains($o, 'UNIT_UNRESOLVED total')
    && str_contains($o, 'still blocked') && str_contains($o, 'NOTHING WAS CREATED') && $snap() === $before, substr($o, 0, 400));
[$code, $o] = $cli('kt_blocker_resolution.php', '--app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($tmp . '/blocked.xlsx') . ' --out=' . escapeshellarg($root . '/out_must_not_exist'));
check('C6 CLI refuses an --out inside the application tree (exit 3)', $code === 3 && !is_dir($root . '/out_must_not_exist'));

echo "\n== D. approved resolutions in the opening preview ==\n";
$wr = static function (string $name, array $rows) use ($tmp): string {
    $p = $tmp . "/{$name}.csv";
    $f = fopen($p, 'w');
    fputcsv($f, ['kind', 'source_code', 'item_id', 'master_sku', 'source_uom', 'base_qty_per_source_unit', 'evidence', 'approved_by'], ',', '"', '');
    foreach ($rows as $x) {
        fputcsv($f, $x, ',', '"', '');
    }
    fclose($f);
    return $p;
};
$approved = $wr('res_ok', [
    ['MAP_ITEM', '900251', $ey60, 'PWN-EY-60', '', '', 'nama persis sama, kode master berbeda', 'Budi'],
    ['MAP_ITEM', '120118', $susu, '0120118', '', '', 'kode sama setelah normalisasi', 'Budi'],
    ['UNIT_FACTOR', 'UR1', '', '', 'Pcs', '0.5', 'versi konversi kedaluwarsa + riwayat IN 1 PCS = 0.5 KG', 'Budi'],
    ['UNIT_FACTOR', 'PHYS1', '', '', 'Gram', '0.001', 'skala fisik standar', 'Budi'],
    ['MAP_ITEM', 'AMB1', $tp1, 'TRG-BIRU', '', '', 'belum disetujui', ''],
]);
$res = kt_load_resolutions($approved);
check('D1 loader: 2 MAP_ITEM + 2 UNIT_FACTOR approved, the row without approved_by is IGNORED (listed), no parse error', count($res['map']) === 2 && count($res['unit']) === 2 && count($res['ignored']) === 1 && $res['errors'] === [] && $res['sha256'] !== '', json_encode($res['ignored']));
$p2 = kt_plan($pdo, $src, KT_WAREHOUSE_CODE, KT_EFFECTIVE_DATE, $res);
$x = $by($p2, '900251');
check('D2 900251 is now MATCH_EXACT through its approved mapping to PWN-EY-60 (ML = base ML, ×1), postable, resolution column MAP_ITEM, NOT_FOUND no longer for it', $x['status'] === 'MATCH_EXACT' && $x['item_id'] === $ey60 && $x['postable'] && $x['resolution'] === 'MAP_ITEM');
$x = $by($p2, 'UR1');
check('D3 UR1 → MATCH_WITH_UNIT_CONVERSION via the approved factor 0.5: qty 4 PCS → 2 KG, HPP 25.000 → 50.000, value identical; the conversion text names the approver + evidence', $x['status'] === 'MATCH_WITH_UNIT_CONVERSION' && $near($x['norm_qty'], 2.0) && $near($x['norm_unit_hpp'], 50000.0) && $near($x['norm_value'], $x['source_value'], 0.5) && str_contains((string) $x['unit_conversion'], 'Budi') && $x['resolution'] === 'UNIT_FACTOR');
$x = $by($p2, 'PHYS1');
check('D4 PHYS1 → 3000 Gram = 3 KG, HPP 10 → 10.000/kg, value preserved', $x['status'] === 'MATCH_WITH_UNIT_CONVERSION' && $near($x['norm_qty'], 3.0) && $near($x['norm_unit_hpp'], 10000.0) && $near($x['norm_value'], $x['source_value'], 0.5));
check('D5 rows that are still blocked stay blocked: 900245, 201004, 100430, 444303, NEW2, AMB1, DUP1, DUP2, NEW3 (NOT_FOUND / AMBIGUOUS), 100313 / UC1 / HINT1 / HINT2 (UNIT_UNRESOLVED)', $p2['blocked'] && array_column(array_filter($p2['rows'], static fn ($r) => $r['status'] === 'UNIT_UNRESOLVED'), 'source_code') === ['100313', 'UC1', 'HINT1', 'HINT2'] && $by($p2, '900245')['status'] === 'NOT_FOUND');
check('D6 the plan reports the applied resolutions and its sha256 differs from the baseline; same input → same sha; a different approver → a different sha', $p2['resolutions']['digest'] !== '' && count($p2['resolutions']['applied']) === 4 && $p2['preview_sha'] !== $plan['preview_sha']
    && kt_plan($pdo, $src, KT_WAREHOUSE_CODE, KT_EFFECTIVE_DATE, kt_load_resolutions($approved))['preview_sha'] === $p2['preview_sha']
    && kt_plan($pdo, $src, KT_WAREHOUSE_CODE, KT_EFFECTIVE_DATE, kt_load_resolutions($wr('res_other', [['MAP_ITEM', '900251', $ey60, 'PWN-EY-60', '', '', 'nama persis sama, kode master berbeda', 'Siti'], ['MAP_ITEM', '120118', $susu, '0120118', '', '', 'kode sama setelah normalisasi', 'Budi'],
        ['UNIT_FACTOR', 'UR1', '', '', 'Pcs', '0.5', 'versi konversi kedaluwarsa + riwayat IN 1 PCS = 0.5 KG', 'Budi'], ['UNIT_FACTOR', 'PHYS1', '', '', 'Gram', '0.001', 'skala fisik standar', 'Budi']])))['preview_sha'] !== $p2['preview_sha']);
$bad = kt_load_resolutions($wr('res_bad', [
    ['MAP_ITEM', '900251', $rb60, 'PWN-EY-60', '', '', 'sku tidak cocok dengan item_id', 'Budi'],          // item_id / sku mismatch
    ['MAP_ITEM', 'CTL001', $ctl, 'CTL001', '', '', 'kode sudah ada', 'Budi'],                             // the code already matches exactly
    ['MAP_ITEM', 'ZZZ999', $ey60, 'PWN-EY-60', '', '', 'kode tidak ada di sumber', 'Budi'],              // unused
    ['UNIT_FACTOR', 'CTL001', '', '', 'Kg', '2', 'menimpa master', 'Budi'],                                // the unit already resolves in the master
    ['UNIT_FACTOR', 'UC1', '', '', 'Pcs', '0.5', '', 'Budi'],                                              // no evidence text
    ['BOGUS', 'X', '', '', '', '', 'e', 'Budi'],
]));
$p3 = kt_plan($pdo, $src, KT_WAREHOUSE_CODE, KT_EFFECTIVE_DATE, $bad);
$g = json_encode($p3['blockers']);
check('D7 invalid resolutions are BLOCKERS, never silently applied: sku/item_id mismatch, code already exact, unused code, overriding a resolved unit, missing evidence, unknown kind', str_contains($g, 'SKU-nya bukan') !== false && str_contains($g, 'sudah cocok tepat') && str_contains($g, 'tidak dipakai') && str_contains($g, 'tidak boleh menimpa master') && str_contains($g, 'evidence wajib') && str_contains($g, 'kind harus')
    && $by($p3, '900251')['status'] === 'NOT_FOUND', substr($g, 0, 600));
$unk = kt_plan($pdo, $src, KT_WAREHOUSE_CODE, KT_EFFECTIVE_DATE, kt_load_resolutions($wr('res_unused2', [['UNIT_FACTOR', '900251', '', '', 'Ml', '1', 'unit untuk kode yang belum terpetakan', 'Budi']])));
check('D8 a UNIT_FACTOR for a code that is not mapped (so its unit step never runs) is reported as unused', str_contains(json_encode($unk['blockers']), 'UNIT_FACTOR 900251: tidak dipakai'));
check('D9 Master Barang untouched by resolutions (no item / conversion created; checksums identical)', $snap() === $before);

echo "\n== E. a fully resolved workbook → clean preview (exit 0) and a bound post ==\n";
$rowsClean = [
    $row('CTL001', 'GULA PASIR CONTROL', 'Kg', 14000, 10),
    $row('900251', 'PEWARNA CROSS EGG YELLOW @60ML', 'Ml', 616.6666667, 900),
    $row('120118', 'SUSU VAMOLA', 'Ml', 500, 240),
    $row('UR1', 'GULA HALUS', 'Pcs', 25000, 4),
    $row('PHYS1', 'GARAM HALUS', 'Gram', 10, 3000),
];
$sumC = 0.0;
foreach ($rowsClean as $x) {
    $sumC += $x[4] * $x[6];
}
$clean = $mkXlsx('clean', $rowsClean, $sumC);
$base = '--app-root=' . escapeshellarg($root) . ' --source=' . escapeshellarg($clean);
$okRes = $wr('res_clean', [
    ['MAP_ITEM', '900251', $ey60, 'PWN-EY-60', '', '', 'nama persis sama, kode master berbeda', 'Budi'],
    ['MAP_ITEM', '120118', $susu, '0120118', '', '', 'kode sama setelah normalisasi', 'Budi'],
    ['UNIT_FACTOR', 'UR1', '', '', 'Pcs', '0.5', 'versi konversi kedaluwarsa + riwayat IN 1 PCS = 0.5 KG', 'Budi'],
    ['UNIT_FACTOR', 'PHYS1', '', '', 'Gram', '0.001', 'skala fisik standar', 'Budi'],
]);
[$code, $o] = $cli('kt_opening.php', "preview {$base}");
check('E1 without resolutions the clean workbook is BLOCKED (exit 11): NOT_FOUND 2, UNIT_UNRESOLVED 2', $code === 11 && str_contains($o, 'NOT_FOUND                     : 2') && str_contains($o, 'UNIT_UNRESOLVED               : 2'), substr($o, 0, 200));
[$code, $o] = $cli('kt_opening.php', "preview {$base} --resolutions=" . escapeshellarg($okRes));
preg_match('/PREVIEW SHA256 : ([0-9a-f]{64})/', $o, $mm);
$shaRes = $mm[1] ?? '';
check('E2 with the approved resolutions: PREVIEW_EXIT=0, NOT_FOUND 0 / AMBIGUOUS 0 / UNIT_UNRESOLVED 0 / HPP_MISSING 0 / QTY_INVALID 0 / ITEM_INACTIVE 0, "SIAP", resolutions printed with approver + evidence',
    $code === 0 && str_contains($o, 'NOT_FOUND                     : 0') && str_contains($o, 'AMBIGUOUS                     : 0') && str_contains($o, 'UNIT_UNRESOLVED               : 0') && str_contains($o, 'HPP_MISSING                   : 0') && str_contains($o, 'QTY_INVALID / ITEM_INACTIVE   : 0 / 0')
    && str_contains($o, 'SIAP (tidak ada blocker)') && str_contains($o, 'RESOLUSI YANG DISETUJUI') && str_contains($o, 'Budi') && $shaRes !== '', "exit {$code}\n" . substr($o, -900));
$beforePost = $snap();
[$code, $o] = $cli('kt_opening.php', "post {$base} --preview-sha={$shaRes} --actor=kr_super --yes");
check('E3 post WITHOUT the resolutions file: the reviewed sha does not match / rows blocked → refused, nothing written', in_array($code, [11, 13], true) && (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = '" . KT_REFERENCE . "'")->fetchColumn() === 0, "exit {$code}");
[$code, $o] = $cli('kt_opening.php', "post {$base} --resolutions=" . escapeshellarg($wr('res_tamper', [
    ['MAP_ITEM', '900251', $ey60, 'PWN-EY-60', '', '', 'nama persis sama, kode master berbeda', 'Siti'], ['MAP_ITEM', '120118', $susu, '0120118', '', '', 'kode sama setelah normalisasi', 'Budi'],
    ['UNIT_FACTOR', 'UR1', '', '', 'Pcs', '0.5', 'versi konversi kedaluwarsa + riwayat IN 1 PCS = 0.5 KG', 'Budi'], ['UNIT_FACTOR', 'PHYS1', '', '', 'Gram', '0.001', 'skala fisik standar', 'Budi']])) . " --preview-sha={$shaRes} --actor=kr_super --yes");
check('E4 post with a resolutions file that differs from the reviewed one (other approver): exit 13 preview mismatch, nothing written', $code === 13 && (int) $pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = '" . KT_REFERENCE . "'")->fetchColumn() === 0, "exit {$code}");
[$code, $o] = $cli('kt_opening.php', "post {$base} --resolutions=" . escapeshellarg($okRes) . " --preview-sha={$shaRes} --actor=kr_super --yes");
check('E5 post with the reviewed resolutions: exit 0, 5 OPENING lines', $code === 0 && str_contains($o, 'POSTED 5 OPENING lines'), "exit {$code}: " . substr($o, -200));
$lines = $pdo->query("SELECT l.item_id, l.base_qty, l.unit_cost_base, l.subtotal FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id = t.id WHERE t.reference_no = '" . KT_REFERENCE . "' ORDER BY l.item_id")->fetchAll();
$byItem = [];
foreach ($lines as $l) {
    $byItem[(int) $l['item_id']] = $l;
}
check('E6 ledger: 900251 posted on PWN-EY-60 (900 ML), 120118 on 0120118 (240 ML), UR1 2 KG @ 50.000, PHYS1 3 KG @ 10.000 — values equal the source values', count($lines) === 5 && $near((float) $byItem[$ey60]['base_qty'], 900.0) && $near((float) $byItem[$susu]['base_qty'], 240.0) && $near((float) $byItem[$ur1]['base_qty'], 2.0) && $near((float) $byItem[$ur1]['unit_cost_base'], 50000.0)
    && $near((float) $byItem[$ph]['base_qty'], 3.0) && $near((float) $byItem[$ph]['unit_cost_base'], 10000.0) && $near((float) $byItem[$ur1]['subtotal'], 100000.0, 0.5), json_encode($byItem));
$hdrA = json_decode((string) $pdo->query("SELECT after_data FROM audit_logs WHERE action_code = 'KARANG_OPENING_POST'")->fetchColumn(), true);
$lineA = [];
foreach ($pdo->query("SELECT after_data FROM audit_logs WHERE action_code = 'KARANG_OPENING_LINE'")->fetchAll(PDO::FETCH_COLUMN) as $j) {
    $d = json_decode((string) $j, true);
    if (($d['source_code'] ?? '') === '900251') {
        $lineA = $d;
    }
}
check('E7 audit keeps the approved resolutions (header: applied rows + file sha; line: resolution kind) so the mapping is traceable', count($hdrA['approved_resolutions'] ?? []) === 4 && !empty($hdrA['resolutions_file_sha256']) && ($lineA['resolution'] ?? '') === 'MAP_ITEM', json_encode([$hdrA['approved_resolutions'] ?? null, $lineA['resolution'] ?? null]));
$chk = (int) $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
check('E8 no master item was created at any point (the only items are the fixture ones)', $chk === (int) $pdo->query("SELECT COUNT(*) FROM items WHERE sku IN ('CTL001','PWN-EY-60','PWN-RB-60','0120118','AQ-220','TRG-BIRU','TRG-MERAH','MG-1','100313','UR1','UC1','HINT1','HINT2','PHYS1','INACT9')")->fetchColumn());

$ok = count(array_filter($results));
echo "\n{$ok} / " . count($results) . " PASSED\n";
exit($ok === count($results) ? 0 : 1);
