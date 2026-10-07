<?php
declare(strict_types=1);

/**
 * October opening — BOTH corrections together ("Semua Gudang"):
 *   October opening = SCM / Cibadak opening AFTER the 30-Sep Stock Opname adjustment (period cutoff) + Karang Tengah opening balance (effective 2026-10-01),
 *   no double counting; October Stock IN contains neither; October Adjustment contains neither; dashboard == Reports V3; nothing else touched.
 *
 * Usage: php tests/october_opening_combined_test.php
 */

foreach (glob(__DIR__ . '/../services/*.php') as $f) {
    if (basename($f) !== 'ReportsV3Routes.php') {
        require_once $f;
    }
}
require_once __DIR__ . '/lib/jejak_real_fixture.php';
require_once __DIR__ . '/../scripts/rv3/pc_lib.php';
require_once __DIR__ . '/../scripts/rv3/kt_opening_lib.php';
require_once __DIR__ . '/../scripts/rv3/of_lib.php';

use App\Services\DashboardInventoryService as D;
use App\Services\Database;
use App\Services\ExcelWriterService;
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
$pdo = Database::connection();
$fx = jejak_build_fixture($pdo);
$L = $fx['legacy'];
$sid = (int) $L['session_id'];
$uuid = (string) $pdo->query("SELECT session_uuid FROM stock_opname_sessions WHERE id = {$sid}")->fetchColumn();
$pdo->prepare("UPDATE stock_opname_sessions SET session_date = '2026-10-02' WHERE id = :i")->execute(['i' => $sid]);
$pdo->prepare("UPDATE inventory_transactions SET transaction_date = '2026-10-02 23:59:59' WHERE transaction_uuid LIKE :u")->execute(['u' => $uuid . ':%']);
$admin = (int) $fx['admin']['id'];
$today = min(date('Y-m-d'), '2026-10-31');

// ---- Karang Tengah master + a 3-row physical SO file
$kgId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$pdo->exec("INSERT INTO items (sku, name, base_unit_id, status) VALUES ('KT1','Karang item 1',{$kgId},'ACTIVE'), ('KT2','Karang item 2',{$kgId},'ACTIVE'), ('KT3','Karang zero',{$kgId},'ACTIVE')");
$pdo->exec("INSERT INTO warehouses (code, name, warehouse_type, is_active, activation_locked) VALUES ('KARANG_TENGAH', 'Gudang Karang Tengah', 'TRANSIT', 1, 1)");   // the production state: ACTIVE + LOCKED
$khId = (int) $pdo->query("SELECT id FROM warehouses WHERE code='KARANG_TENGAH'")->fetchColumn();
$tmp = sys_get_temp_dir() . '/oc_' . bin2hex(random_bytes(3)) . '.xlsx';
ExcelWriterService::write($tmp, ['S' => ['headers' => ['', '', '', '', '', '', '', 1000 * 12.5 + 40 * 100.25], 'rows' => [
    ['NO', 'ITEM', 'kode barang', 'UoM', 'HARGA', 'Stock Akhir Produksi', 'Stock Akhir Gudang', 'Jumlah '],
    [1, 'Karang item 1', 'KT1', 'kg', 12.5, 600, 400, ''], [2, 'Karang item 2', 'KT2', 'Kg', 100.25, '', 40, ''], [3, 'Karang zero', 'KT3', 'KG', 0, '', '', ''],
]]]);
$ktValue = 1000 * 12.5 + 40 * 100.25;

echo "== BEFORE both corrections ==\n";
$fig = static function (?int $w, string $a, string $b) use ($pdo): array {
    InventoryEffectiveDateService::resetCache();
    return V3::overview($pdo, $a, $b, $w)['split_totals'];
};
$allB = $fig(null, '2026-10-01', '2026-10-31');
$whB = $fig((int) $L['warehouse_id'], '2026-10-01', '2026-10-31');
$dashB = D::overview($pdo, null, 'custom', '2026-10-01', $today)['movement'];
check('0 baseline: SO adjustment (3.500) is an October movement in the company-wide October adjustment', $near($allB['adjustment'], 3500.0) && $near($allB['in'], 0.0), json_encode([$allB['opening'], $allB['in'], $allB['adjustment']]));

// ---- the READ-ONLY forecast, computed BEFORE anything is written
$plan = pc_plan($pdo, [$sid], '2026-09-30');
$src = kt_read_source($tmp);
$kp = kt_plan($pdo, $src);
$fc = of_forecast($pdo, $plan, $kp);
// ---- 1. period cutoff
pc_post($pdo, $plan, $fx['admin']['username'], $plan['preview_sha']);
// ---- 2. Karang Tengah opening
check('K1 Karang plan: 2 postable rows (KT3 zero qty / HPP 0 informational), no blocker, value ' . $ktValue, $kp['blocked'] === false && $kp['summary']['rows']['postable'] === 2 && $near($kp['summary']['normalized_value'], $ktValue), json_encode($kp['blockers']));
kt_post($pdo, $kp, $fx['admin']['username'], $kp['preview_sha']);
// kt_post requires SUPERADMIN: the fixture admin is one
echo "\n== AFTER both corrections ==\n";
$all = $fig(null, '2026-10-01', '2026-10-31');
$kh = $fig($khId, '2026-10-01', '2026-10-31');
$wh = $fig((int) $L['warehouse_id'], '2026-10-01', '2026-10-31');
check('1 Semua Gudang: October opening = (before) + 3.500 SO adjustment (cutoff 30 Sep) + Karang Tengah opening', $near($all['opening'], $allB['opening'] + 3500.0 + $ktValue), sprintf('%.2f = %.2f + 3500 + %.2f', $all['opening'], $allB['opening'], $ktValue));
check('2 Semua Gudang: October opening = Cibadak/SCM opening (after the Sep adjustment) + Karang Tengah opening — no double counting (Σ warehouses)', $near($all['opening'], $wh['opening'] + $kh['opening'] + ($allB['opening'] - $whB['opening'])) && $near($kh['opening'], $ktValue), sprintf('wh=%.2f kh=%.2f others=%.2f', $wh['opening'], $kh['opening'], $allB['opening'] - $whB['opening']));
check('3 October Stock IN unchanged (neither the SO adjustment nor Karang Tengah is Stock IN / purchase)', $near($all['in'], $allB['in']) && $near($kh['in'], 0.0));
check('4 October Adjustment = 0 (the SO adjustment is September; Karang Tengah opening is not an adjustment) and Transfer IN / OUT untouched', $near($all['adjustment'], 0.0) && $near($kh['adjustment'], 0.0) && $near($all['tin'], $allB['tin']) && $near($all['tout'], $allB['tout']));
check('5 October closing grows only by the Karang Tengah opening (SO adjustment moved inside the opening, closing unchanged)', $near($all['closing'], $allB['closing'] + $ktValue));
check('6 identity opening + IN − OUT + TIN − TOUT + ADJ = closing (all warehouses, Karang Tengah, Cibadak)', $near($all['difference'], 0.0) && $near($kh['difference'], 0.0) && $near($wh['difference'], 0.0));
check('7 company-wide Transfer IN − Transfer OUT = in-transit only (no fake transfer created)', $near($all['tin'] - $all['tout'], $allB['tin'] - $allB['tout']));
$dash = D::overview($pdo, null, 'custom', '2026-10-01', $today)['movement'];
$v3d = $fig(null, '2026-10-01', $today);
check('8 Dashboard (Semua Gudang, October to date) == Reports V3: Stok Awal / IN / OUT / Adjustment / Stok Akhir', $near((float) $dash['opening_stock']['value'], $v3d['opening']) && $near((float) $dash['stock_in']['value'], $v3d['in']) && $near((float) $dash['stock_out']['value'], $v3d['out']) && $near((float) $dash['adjustment']['value'], $v3d['adjustment']) && $near((float) $dash['closing_stock']['value'], $v3d['closing']), json_encode([$dash['opening_stock']['value'], $dash['adjustment']['value'], $dash['closing_stock']['value']]));
check('9 Dashboard opening rose by exactly 3.500 + Karang Tengah opening; adjustment fell by 3.500', $near((float) $dash['opening_stock']['value'], (float) $dashB['opening_stock']['value'] + 3500.0 + $ktValue) && $near((float) $dash['adjustment']['value'], (float) $dashB['adjustment']['value'] - 3500.0));
check('10 Dashboard reconciliation ok (identity 0, drill rows == cards, adjustment explained)', $dash['reconciliation']['ok'] === true && $near((float) $dash['components']['difference'], 0.0));
$sep = $fig(null, '2026-09-01', '2026-09-30');
check('11 September company closing == October opening minus Karang Tengah (the Karang opening starts 1 Oct; the SO adjustment closes September)', $near($sep['closing'], $all['opening'] - $ktValue), sprintf('%.2f vs %.2f', $sep['closing'], $all['opening'] - $ktValue));
$vk = array_filter(kt_verify_ledger($pdo, kt_plan($pdo, $src)), static fn ($c) => !$c[1]);
$vr = array_filter(kt_verify_reports($pdo, kt_plan($pdo, $src)), static fn ($c) => !$c[1]);
check('12 Karang Tengah ledger + report verification (opening qty == on-hand == FIFO layer, classification) all PASS', $vk === [] && $vr === [], json_encode(array_values(array_merge($vk, $vr))));
// ---- the forecast against reality
InventoryEffectiveDateService::resetCache();
$bad = [];
foreach ($fc['rows'] as $r) {
    $a = V3::overview($pdo, '2026-10-01', '2026-10-31', $r['warehouse_id'])['split_totals'];
    foreach (['opening', 'in', 'adjustment', 'closing'] as $k) {
        if (!$near((float) $a[$k], $r['after'][$k])) {
            $bad[] = "{$r['code']}.{$k}: forecast {$r['after'][$k]} actual {$a[$k]}";
        }
    }
}
$allA = V3::overview($pdo, '2026-10-01', '2026-10-31', null)['split_totals'];
foreach (['opening', 'in', 'adjustment', 'closing'] as $k) {
    if (!$near((float) $allA[$k], $fc['company']['after'][$k])) {
        $bad[] = "ALL.{$k}: forecast {$fc['company']['after'][$k]} actual {$allA[$k]}";
    }
}
check('13 the READ-ONLY forecast made before writing == the real October figures afterwards (every warehouse and company-wide: opening, Stock IN, adjustment, closing)', $bad === [], implode(' | ', $bad));
check('14 forecast invariants all hold (no double counting; company closing moves only by the Karang opening; Stock IN unchanged; Σ warehouses == Semua Gudang)', count(array_filter($fc['invariants'], static fn ($i) => !$i[1])) === 0, json_encode($fc['invariants']));
$lines = implode("\n", of_lines($fc));
check('15 the printed forecast lists every warehouse + SEMUA GUDANG with SEBELUM → SESUDAH', str_contains($lines, 'SEMUA GUDANG') && str_contains($lines, 'Awal SESUDAH') && substr_count($lines, 'PASS - ') === 4);
@unlink($tmp);
$ok = count(array_filter($results));
echo "\n{$ok} / " . count($results) . " PASSED\n";
exit($ok === count($results) ? 0 : 1);
