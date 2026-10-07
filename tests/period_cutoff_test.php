<?php
declare(strict_types=1);

/**
 * PERIOD CUTOFF — Stock Opname adjustments with an inventory cutoff of 30 Sep 2026 close SEPTEMBER and open OCTOBER (scripts/rv3/period_cutoff.php,
 * services/InventoryEffectiveDateService.php, the reporting SQL of Movement / Dashboard / HPP / Valuation).
 *
 * Reproduces the production situation: a POSTED Stock Opname (real services, tests/lib/jejak_real_fixture.php) that was counted and posted in October — its adjustments are
 * dated 2026-10-02 — while its inventory cutoff is 30 Sep. Before the script: the adjustment is an October movement. After: it is in September's closing and October's opening,
 * October's adjustment contains only October-effective adjustments, no double counting, and NOTHING of the ledger / session / adjustment / audit trail was edited.
 *
 * Usage: php tests/period_cutoff_test.php
 */

foreach (glob(__DIR__ . '/../services/*.php') as $f) {
    if (basename($f) !== 'ReportsV3Routes.php') {
        require_once $f;
    }
}
require_once __DIR__ . '/lib/jejak_real_fixture.php';
require_once __DIR__ . '/../scripts/rv3/pc_lib.php';

use App\Services\Database;
use App\Services\DashboardInventoryService;
use App\Services\FifoService;
use App\Services\InventoryEffectiveDateService;
use App\Services\InventoryHppReportService;
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
$root = dirname(__DIR__);

$fx = jejak_build_fixture($pdo);
$L = $fx['legacy'];
$sid = (int) $L['session_id'];
$wh = (int) $L['warehouse_id'];
$uuid = (string) $pdo->query("SELECT session_uuid FROM stock_opname_sessions WHERE id = {$sid}")->fetchColumn();
// ---- production state: counted + posted in October (session date and the ledger rows say 2 Oct), inventory cutoff is 30 Sep
$pdo->prepare("UPDATE stock_opname_sessions SET session_date = '2026-10-02' WHERE id = :i")->execute(['i' => $sid]);
$pdo->prepare("UPDATE inventory_transactions SET transaction_date = '2026-10-02 23:59:59', posting_date = '2026-10-05 10:00:00' WHERE transaction_uuid LIKE :u")->execute(['u' => $uuid . ':%']);
$txIds = array_map('intval', $pdo->query("SELECT id FROM inventory_transactions WHERE transaction_uuid LIKE '{$uuid}:%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$admin = (int) $fx['admin']['id'];
$kg = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();
$item1 = (int) $L['items']['L1']['id'];
$item2 = (int) $L['items']['L2']['id'];
// a REAL September movement and a REAL October movement in the same warehouse — only the SO adjustment may move
FifoService::postIn($pdo, ['transaction_uuid' => 'PC-SEP-IN', 'item_id' => $item2, 'warehouse_id' => $wh, 'input_qty' => 4, 'input_unit_id' => $kg, 'unit_price_input' => 2100, 'transaction_type' => 'IN', 'transaction_date' => '2026-09-15 09:00:00', 'created_by' => $admin, 'anomaly_approved_by' => $admin]);
FifoService::postIn($pdo, ['transaction_uuid' => 'PC-OCT-IN', 'item_id' => $item1, 'warehouse_id' => $wh, 'input_qty' => 7, 'input_unit_id' => $kg, 'unit_price_input' => 1500, 'transaction_type' => 'IN', 'transaction_date' => '2026-10-10 09:00:00', 'created_by' => $admin, 'anomaly_approved_by' => $admin]);

$cli = static function (string $args) use ($root): array {
    $o = [];
    exec('php ' . escapeshellarg($root . '/scripts/rv3/period_cutoff.php') . ' ' . $args . ' 2>&1', $o, $code);
    return [$code, implode("\n", $o)];
};
$base = '--app-root=' . escapeshellarg($root) . " --sessions={$sid} --cutoff=2026-09-30";
$snap = static function () use ($pdo): array {
    $o = [];
    foreach (['inventory_transactions', 'inventory_transaction_lines', 'inventory_batches', 'fifo_allocations', 'stock_adjustments', 'stock_opname_sessions', 'stock_opname_lines', 'stock_opname_findings', 'warehouse_transfers'] as $t) {
        $r = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM);
        $o[$t] = $r[1];
    }
    return $o;
};
$figs = static function (?int $w) use ($pdo): array {
    InventoryEffectiveDateService::resetCache();
    return [
        'sep' => V3::overview($pdo, '2026-09-01', '2026-09-30', $w)['split_totals'],
        'oct' => V3::overview($pdo, '2026-10-01', '2026-10-31', $w)['split_totals'],
    ];
};

echo "== A. BEFORE: the SO adjustment is an October movement ==\n";
$ledgerX = (float) $pdo->query("SELECT COALESCE(SUM(l.subtotal),0) FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id WHERE t.id IN (" . implode(',', $txIds) . ')')->fetchColumn();
$b = $figs($wh);
$ball = $figs(null);
check('A1 fixture: 3 SO adjustment transactions dated 2026-10-02 (posted 2026-10-05); net value 3.500 (hand-computed: −5.000 + 10.000 − 1.500)', count($txIds) === 3 && $near($ledgerX, 3500.0), 'tx=' . count($txIds) . ' value=' . $ledgerX);
check('A2 before: October Adjustment contains the SO net (3.500) and September has none', $near($b['oct']['adjustment'], 3500.0) && $near($b['sep']['adjustment'], 0.0), json_encode([$b['oct']['adjustment'], $b['sep']['adjustment']]));
check('A3 inventory_effective_dates is empty/dormant: reporting date == transaction_date (no behaviour change)', InventoryEffectiveDateService::col($pdo) === 't.transaction_date');
$today = min(date('Y-m-d'), '2026-10-31');   // the dashboard refuses a future end date
$dashBefore = DashboardInventoryService::overview($pdo, $wh, 'custom', '2026-10-01', $today)['movement'];
$octDBefore = V3::overview($pdo, '2026-10-01', $today, $wh)['split_totals'];
$hppBefore = InventoryHppReportService::summary($pdo, '2026-10-01', $today, $wh);
$snapBefore = $snap();
$empty = static fn (): bool => (int) $pdo->query('SELECT COUNT(*) FROM inventory_effective_dates')->fetchColumn() === 0;

echo "\n== B. preview (read only) ==\n";
[$code, $o] = $cli("preview {$base}");
$plan = pc_plan($pdo, [$sid], '2026-09-30');
check('B1 CLI preview exit 0; lists 3 transactions with original dates and the proposed effective_at 2026-09-30 23:59:59', $code === 0 && substr_count($o, '2026-10-02 23:59:59') >= 3 && str_contains($o, '2026-09-30 23:59:59') && str_contains($o, $plan['preview_sha']), "exit {$code}");
check('B2 preview shows BEFORE → AFTER: Stok Awal +3.500, Adjustment −3.500, Stok Akhir unchanged', str_contains($o, 'Stok Awal') && str_contains($o, 'Adjustment') && str_contains($o, 'tidak berubah') && $near($plan['impact'][1]['shift_value'], 3500.0), json_encode($plan['impact'][1] ?? null));
check('B3 preview wrote nothing (no table, ledger identical)', $empty() && $snap() === $snapBefore);
check('B4 plan: 3 to write, no blocker', $plan['to_write'] === 3 && $plan['blocked'] === false, json_encode($plan['blockers']));
[$code, $o] = $cli("post {$base} --preview-sha={$plan['preview_sha']} --actor=" . $fx['admin']['username']);
check('B5 post without --yes: exit 10 NOT APPLIED, nothing written', $code === 10 && str_contains($o, 'NOT APPLIED') && $empty());
[$code, $o] = $cli("post {$base} --preview-sha=" . str_repeat('0', 64) . ' --actor=' . $fx['admin']['username'] . ' --yes');
check('B6 post with a sha that is not the reviewed preview: exit 13, nothing written', $code === 13 && $empty(), $o);
[$code, $o] = $cli("preview --app-root=" . escapeshellarg($root) . " --sessions=999999 --cutoff=2026-09-30");
check('B7 unknown session → blocked (exit 11)', $code === 11 && str_contains($o, 'tidak ditemukan'));
[$code, $o] = $cli("preview --app-root=" . escapeshellarg($root) . " --sessions={$sid} --cutoff=2026-10-20");
check('B8 a cutoff LATER than the transaction date → blocked (effective date can never be moved forward)', $code === 11 && str_contains($o, 'tidak boleh dimajukan'), substr($o, -300));

echo "\n== C. post ==\n";
$beforeActor = $pdo->query("SELECT id FROM users WHERE username = " . $pdo->quote($fx['admin']['username']))->fetchColumn();
[$code, $o] = $cli("post {$base} --preview-sha={$plan['preview_sha']} --actor=" . $fx['admin']['username'] . ' --yes');
check('C1 post --yes: exit 0, 3 rows written', $code === 0 && str_contains($o, '"written":3'), "exit {$code}: " . substr($o, -200));
check('C2 NOTHING of the ledger / sessions / adjustments / findings / FIFO was edited (checksums identical)', $snap() === $snapBefore);
$rows = $pdo->query('SELECT e.*, t.transaction_date AS live_date, t.posting_date AS live_posting FROM inventory_effective_dates e JOIN inventory_transactions t ON t.id = e.transaction_id')->fetchAll();
check('C3 override rows: effective_at 2026-09-30 23:59:59; original dates stored == live (2026-10-02 23:59:59 / posted 2026-10-05); source = the session', count($rows) === 3 && count(array_filter($rows, static fn ($r) => $r['effective_at'] === '2026-09-30 23:59:59' && $r['original_transaction_date'] === $r['live_date'] && $r['live_date'] === '2026-10-02 23:59:59' && $r['live_posting'] === '2026-10-05 10:00:00' && (int) $r['source_id'] === $GLOBALS['sid'])) === 3);
$a = $figs($wh);
$aall = $figs(null);
check('C4 Warehouse: October OPENING = before + 3.500 (the SO adjustment is already in the opening)', $near($a['oct']['opening'], $b['oct']['opening'] + 3500.0), sprintf('%.4f → %.4f', $b['oct']['opening'], $a['oct']['opening']));
check('C5 Warehouse: October ADJUSTMENT no longer contains it (−3.500 → 0)', $near($a['oct']['adjustment'], $b['oct']['adjustment'] - 3500.0) && $near($a['oct']['adjustment'], 0.0), sprintf('%.4f → %.4f', $b['oct']['adjustment'], $a['oct']['adjustment']));
check('C6 Warehouse: October closing unchanged; September closing = before + 3.500 and September ADJUSTMENT = 3.500', $near($a['oct']['closing'], $b['oct']['closing']) && $near($a['sep']['closing'], $b['sep']['closing'] + 3500.0) && $near($a['sep']['adjustment'], 3500.0));
check('C7 Continuity: September closing == October opening; identity holds in both months (difference 0)', $near($a['sep']['closing'], $a['oct']['opening']) && $near($a['sep']['difference'], 0.0) && $near($a['oct']['difference'], 0.0));
check('C8 real September / October movements are NOT moved (Stock IN of both months identical to before)', $near($a['oct']['in'], $b['oct']['in']) && $near($a['sep']['in'], $b['sep']['in']) && $near($a['oct']['in'], 7 * 1500.0));
check('C9 "Semua Gudang": same shift (opening +3.500, adjustment −3.500, closing unchanged, continuity)', $near($aall['oct']['opening'], $ball['oct']['opening'] + 3500.0) && $near($aall['oct']['adjustment'], $ball['oct']['adjustment'] - 3500.0) && $near($aall['oct']['closing'], $ball['oct']['closing']) && $near($aall['sep']['closing'], $aall['oct']['opening']));
InventoryEffectiveDateService::resetCache();
$dash = DashboardInventoryService::overview($pdo, $wh, 'custom', '2026-10-01', $today)['movement'];
$octD = V3::overview($pdo, '2026-10-01', $today, $wh)['split_totals'];
check('C10 Dashboard "Ringkasan Pergerakan Stok" (October to date) == Reports V3: Stok Awal / Adjustment / Stok Akhir; same +3.500 shift', $near((float) $dash['opening_stock']['value'], $octD['opening']) && $near((float) $dash['adjustment']['value'], $octD['adjustment']) && $near((float) $dash['closing_stock']['value'], $octD['closing']) && $near((float) $dash['opening_stock']['value'], (float) $dashBefore['opening_stock']['value'] + 3500.0) && $near((float) $dash['adjustment']['value'], (float) $dashBefore['adjustment']['value'] - 3500.0), json_encode([$dash['opening_stock']['value'], $dash['adjustment']['value'], $dash['closing_stock']['value']]));
check('C11 Dashboard identity (components) still reconciles exactly', $near((float) $dash['components']['difference'], 0.0) && $dash['reconciliation']['ok'] === true, json_encode($dash['reconciliation']));
InventoryEffectiveDateService::resetCache();
$hppAfter = InventoryHppReportService::summary($pdo, '2026-10-01', $today, $wh);
check('C12 Nilai Stok & HPP (October): opening value +3.500; Stock Opname net in October = 0; ending value unchanged', $near((float) $hppAfter['opening_value'], (float) $hppBefore['opening_value'] + 3500.0) && $near((float) $hppAfter['non_hpp_movements']['opname_net'], 0.0) && $near((float) $hppAfter['ending_value'], (float) $hppBefore['ending_value']), json_encode([$hppBefore['opening_value'], $hppAfter['opening_value'], $hppAfter['non_hpp_movements']['opname_net']]));
$trailSep = \App\Services\MovementDailyReportService::itemTrail($pdo, '2026-09-30', $item1, $wh);
$trailOct = \App\Services\MovementDailyReportService::itemTrail($pdo, '2026-10-02', $item1, $wh);
$so = array_values(array_filter($trailSep['rows'], static fn ($r) => str_starts_with((string) ($r['reference_no'] ?? ''), 'x') || $r['type'] === 'ADJUSTMENT' || $r['type'] === 'OPNAME'));
check('C13 drill-down: the SO adjustment is listed on 30 Sep (reporting date 2026-09-30 23:59:59) with its REAL posting time (created_at, unchanged) and is gone from 2 Oct', count($so) >= 1 && $so[0]['timestamp'] === '2026-09-30 23:59:59' && $so[0]['posted_at'] === (string) $pdo->query('SELECT created_at FROM inventory_transactions WHERE id = ' . (int) $so[0]['transaction_id'])->fetchColumn() && $so[0]['posted_at'] !== '2026-09-30 23:59:59' && count(array_filter($trailOct['rows'], static fn ($r) => in_array($r['type'], ['ADJUSTMENT', 'OPNAME'], true))) === 0, json_encode(array_map(static fn ($r) => [$r['type'], $r['timestamp'], $r['posted_at']], $trailSep['rows'])));
$audit = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'PERIOD_CUTOFF_SET'")->fetchColumn();
check('C14 audit: one PERIOD_CUTOFF_SET entry with before/after figures', (int) $audit === 1);
[$code, $o] = $cli("verify {$base}");
check('C15 CLI verify: exit 0', $code === 0 && str_contains($o, 'VERIFY OK'), $o);
$plan2 = pc_plan($pdo, [$sid], '2026-09-30');
check('C16 idempotent: a second plan has nothing to write (ALREADY_SET); posting again writes nothing', $plan2['to_write'] === 0 && count(array_filter($plan2['transactions'], static fn ($r) => $r['state'] === 'ALREADY_SET')) === 3);
[$code, $o] = $cli("post {$base} --preview-sha={$plan2['preview_sha']} --actor=" . $fx['admin']['username'] . ' --yes');
check('C17 posting again: exit 0, "nothing to write", still 3 rows', $code === 0 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_effective_dates')->fetchColumn() === 3, $o);
[$code, $o] = $cli("preview --app-root=" . escapeshellarg($root) . " --sessions={$sid} --cutoff=2026-09-29");
check('C18 a different cutoff over existing rows → CONFLICT blocker', $code === 11 && str_contains($o, 'sudah punya effective_at'), substr($o, -250));
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, is_active) VALUES ('pc_viewer','x','pc_viewer',(SELECT id FROM roles WHERE code='VIEWER'),1)")->execute();
[$code, $o] = $cli("post {$base} --preview-sha={$plan2['preview_sha']} --actor=pc_viewer --yes");
check('C19 post by a non-SUPERADMIN: exit 14', $code === 14, $o);

echo "\n== D. rollback ==\n";
[$code, $o] = $cli("rollback --app-root=" . escapeshellarg($root) . " --sessions={$sid}");
check('D1 rollback without --yes: exit 10, removes nothing', $code === 10 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_effective_dates')->fetchColumn() === 3);
[$code, $o] = $cli("rollback --app-root=" . escapeshellarg($root) . " --sessions={$sid} --yes --confirm=PERIOD_CUTOFF");
$r = $figs($wh);
check('D2 rollback --yes --confirm: override rows removed; October / September figures are EXACTLY the original ones; ledger still identical', $code === 0 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_effective_dates')->fetchColumn() === 0 && $near($r['oct']['opening'], $b['oct']['opening']) && $near($r['oct']['adjustment'], $b['oct']['adjustment']) && $near($r['sep']['closing'], $b['sep']['closing']) && $snap() === $snapBefore, $o);
check('D3 dormant again: reporting date == transaction_date', InventoryEffectiveDateService::col($pdo) === 't.transaction_date');

$ok = count(array_filter($results));
echo "\n{$ok} / " . count($results) . " PASSED\n";
exit($ok === count($results) ? 0 : 1);
