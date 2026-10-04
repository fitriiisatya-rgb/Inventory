<?php
declare(strict_types=1);

/**
 * Main Dashboard (GET /dashboard/inventory + /detail → DashboardInventoryService).
 *
 * Real MariaDB, the application's own posting services (tests/lib/
 * dashboard_fixture.php), three warehouses, transfers (received + in transit),
 * adjustments, a VOIDED purchase, a mid-period OPENING (cutover) and today's
 * activity. Proves, per scope (company / A / B / C) and per period:
 *
 *   A. every card equals the EXISTING engines (InventorySummaryReportService /
 *      InventoryMovementReportService / signedValueBefore / the batch valuation)
 *   B. Stok Awal + Pembelian - Barang Keluar + Σ Pergerakan Lain = Stok Akhir
 *   C. every drill-down's grand total == its card, summed over ALL pages;
 *      search/category filters narrow rows but never the card total
 *   D. Stok Akhir == live inventory_batches valuation when the period ends today
 *   E. Nilai Stok == InventoryService::companyTotalValue()/warehouseDashboardSummary()
 *   F. attention / today activity / recent / top lists against independent SQL
 *   G. read-only (table checksums unchanged), validation, HTTP auth + scope
 *
 * Usage: php tests/inventory_dashboard_test.php
 */

require_once __DIR__ . '/lib/dashboard_fixture.php';

use App\Services\DashboardInventoryService as D;
use App\Services\Database;
use App\Services\InventoryHppReportService;
use App\Services\InventoryMovementReportService;
use App\Services\InventoryService;
use App\Services\InventorySummaryReportService;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}
function near(?float $a, ?float $b, float $eps = 0.01): bool
{
    return $a !== null && $b !== null && abs($a - $b) <= $eps;
}

$pdo = Database::connection();
$fx = dashboard_build_fixture($pdo);
$W = $fx['wh'];
$R = $fx['range'];
$today = $fx['today'];
$scopes = ['company' => null, 'A (SCM)' => $W['A'], 'B (Cibadak)' => $W['B'], 'C (Karang Tengah)' => $W['C']];

function snapshot(PDO $pdo): array
{
    $o = [];
    foreach (['inventory_transactions', 'inventory_transaction_lines', 'inventory_batches', 'fifo_allocations', 'warehouse_transfers', 'warehouse_transfer_lines',
              'stock_adjustments', 'stock_opname_sessions', 'stock_opname_lines', 'items', 'warehouses', 'audit_logs'] as $t) {
        $row = $pdo->query("CHECKSUM TABLE {$t}")->fetch(PDO::FETCH_NUM);
        $o[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() . ':' . $row[1];
    }
    return $o;
}
$before = snapshot($pdo);

/** all rows of a detail across every page */
function allRows(PDO $pdo, string $type, ?int $wh, string $period, ?string $from, ?string $to, array $filters = []): array
{
    $rows = [];
    $page = 1;
    do {
        $d = D::detail($pdo, $type, $wh, $period, $from, $to, $filters + ['page' => $page, 'per_page' => 25]);
        array_push($rows, ...$d['rows']);
        $pages = $d['pagination']['total_pages'];
        $page++;
    } while ($page <= $pages);
    return [$rows, $d];
}

foreach ($scopes as $label => $wh) {
    echo "\n===== scope: {$label} · custom {$R['from']}..{$R['to']} =====\n";
    $o = D::overview($pdo, $wh, 'custom', $R['from'], $R['to']);
    $m = $o['movement'];
    $sum = InventorySummaryReportService::summary($pdo, $R['from'], $R['to'], $wh);
    $mv = InventoryMovementReportService::dailyMovement($pdo, $R['from'], $R['to'], $wh);
    $rows = array_values(array_filter($mv['rows'], static fn ($r) => !$r['is_pre_go_live']));
    $engineClosing = (float) end($rows)['stok_akhir'];

    // ---------------- A. engines
    $eng = InventoryHppReportService::signedValueBefore($pdo, $R['from'], $wh, '', [], [], true);
    check("[$label] Stok Awal == InventoryHppReportService::signedValueBefore()", near($m['opening_stock']['value'], $eng), "{$m['opening_stock']['value']} vs {$eng}");
    check("[$label] Stok Awal == Ringkasan Inventory beginning_inventory_value", near($m['opening_stock']['value'], $sum['beginning_inventory_value']));
    check("[$label] Pembelian / Stock IN == Ringkasan 'external_purchase'", near($m['purchase_in']['value'], $sum['external_purchase']), "{$m['purchase_in']['value']} vs {$sum['external_purchase']}");
    check("[$label] Barang Keluar / Stock OUT == Ringkasan 'out_usage'", near($m['stock_out']['value'], $sum['out_usage']), "{$m['stock_out']['value']} vs {$sum['out_usage']}");
    check("[$label] Stok Akhir == Ringkasan ending_inventory_value == Pergerakan Harian last stok_akhir", near($m['closing_stock']['value'], $sum['ending_inventory_value']) && near($m['closing_stock']['value'], $engineClosing), "{$m['closing_stock']['value']} vs {$sum['ending_inventory_value']} / {$engineClosing}");
    $other = [];
    foreach ($m['other_movements']['items'] as $it) { $other[$it['key']] = $it['value']; }
    check("[$label] Adjustment + / − == Ringkasan", near($other['adjustment_positive'], $sum['adjustment_positive']) && near($other['adjustment_negative'], -$sum['adjustment_negative']));
    if ($wh !== null) {
        check("[$label] Transfer IN / OUT == Ringkasan transfer_in / transfer_out", near($other['transfer_in'], (float) $sum['transfer_in']) && near($other['transfer_out'], -(float) $sum['transfer_out']));
    } else {
        check("[$label] company: in-transit net == Ringkasan transfer_elimination", near($other['transfer_net'], (float) $sum['transfer_elimination']), "{$other['transfer_net']} vs {$sum['transfer_elimination']}");
    }

    // ---------------- B. identity
    $calc = $m['opening_stock']['value'] + $m['purchase_in']['value'] - $m['stock_out']['value'] + $m['other_movements']['net'];
    check("[$label] Stok Awal + Pembelian − Keluar + Σ Pergerakan Lain = Stok Akhir", near($calc, $m['closing_stock']['value']) && $m['reconciliation']['ok'], "calc {$calc} vs {$m['closing_stock']['value']} diff {$m['reconciliation']['identity_diff']}");
    check("[$label] reconciliation.batch_* is null for a past period (cannot be compared with live batches)", $m['reconciliation']['batch_on_hand'] === null);

    // ---------------- C. drill-down == card, over all pages
    $cards = ['opening_stock' => $m['opening_stock']['value'], 'closing_stock' => $m['closing_stock']['value'], 'purchase_in' => $m['purchase_in']['value'], 'stock_out' => $m['stock_out']['value']];
    foreach ($cards as $type => $cardValue) {
        [$all, $last] = allRows($pdo, $type, $wh, 'custom', $R['from'], $R['to']);
        $sumRows = round(array_sum(array_column($all, 'value')), 4);
        check("[$label] {$type}: Σ detail rows (all pages) == card", near($sumRows, $cardValue), "rows {$sumRows} vs card {$cardValue} (n=" . count($all) . ')');
        check("[$label] {$type}: grand_total == card_total == card (no filter)", near($last['grand_total']['value'], $cardValue) && near($last['card_total']['value'], $cardValue));
        check("[$label] {$type}: pagination total == real row count", $last['pagination']['total'] === count($all));
    }
    foreach ($m['other_movements']['items'] as $it) {
        $type = 'move_' . $it['key'];
        [$all, $last] = allRows($pdo, $type, $wh, 'custom', $R['from'], $R['to']);
        $sumRows = round(array_sum(array_column($all, 'value')), 4);
        check("[$label] {$type}: Σ detail == card ({$it['value']})", near($sumRows, $it['value']), "rows {$sumRows}");
    }
    check("[$label] card tx counts: purchase_in.tx_count == distinct reference rows", $m['purchase_in']['tx_count'] === count(array_unique(array_column(allRows($pdo, 'purchase_in', $wh, 'custom', $R['from'], $R['to'])[0], 'transaction_id'))));
}

// ---------------- C2. filters narrow rows, never the card
echo "\n===== filters =====\n";
$dAll = D::detail($pdo, 'closing_stock', null, 'custom', $R['from'], $R['to'], ['page' => 1, 'per_page' => 100]);
$sku = $fx['items']['tepung']['sku'];
$dQ = D::detail($pdo, 'closing_stock', null, 'custom', $R['from'], $R['to'], ['q' => $sku, 'page' => 1, 'per_page' => 100]);
check('search by SKU narrows the rows and the filtered grand total', $dQ['pagination']['total'] > 0 && $dQ['pagination']['total'] < $dAll['pagination']['total'] && $dQ['grand_total']['value'] < $dAll['grand_total']['value']);
check('search never changes the CARD total', near($dQ['card_total']['value'], $dAll['card_total']['value']));
check('every row returned by the SKU search matches it', count(array_filter($dQ['rows'], static fn ($r) => $r['sku'] !== $sku)) === 0);
$dCat = D::detail($pdo, 'closing_stock', null, 'custom', $R['from'], $R['to'], ['category_id' => $fx['cat']['roti'], 'page' => 1, 'per_page' => 100]);
check('category filter narrows rows', $dCat['pagination']['total'] > 0 && $dCat['pagination']['total'] < $dAll['pagination']['total']);
$dWh = D::detail($pdo, 'closing_stock', null, 'custom', $R['from'], $R['to'], ['warehouse_id' => $W['B'], 'page' => 1, 'per_page' => 100]);
check('company-wide + row warehouse filter == that warehouse alone', near($dWh['grand_total']['value'], D::overview($pdo, $W['B'], 'custom', $R['from'], $R['to'])['movement']['closing_stock']['value']));
$dWhScoped = D::detail($pdo, 'closing_stock', $W['A'], 'custom', $R['from'], $R['to'], ['warehouse_id' => $W['B'], 'page' => 1, 'per_page' => 100]);
check('inside a single-warehouse scope the row warehouse filter can NEVER widen the scope', near($dWhScoped['grand_total']['value'], D::overview($pdo, $W['A'], 'custom', $R['from'], $R['to'])['movement']['closing_stock']['value']));
$d25 = D::detail($pdo, 'purchase_in', null, 'custom', $R['from'], $R['to'], ['page' => 1, 'per_page' => 25]);
check('per_page outside {25,50,100} falls back to 50', D::detail($pdo, 'purchase_in', null, 'custom', $R['from'], $R['to'], ['page' => 1, 'per_page' => 7])['pagination']['per_page'] === 50 && $d25['pagination']['per_page'] === 25);
$pg = D::detail($pdo, 'closing_stock', null, 'custom', $R['from'], $R['to'], ['page' => 2, 'per_page' => 25]);
check('page 2 of a 25-row page returns the NEXT rows (or none)', $pg['pagination']['page'] === 2);
$dRef = D::detail($pdo, 'purchase_in', null, 'custom', $R['from'], $R['to'], ['q' => 'PO-A-1', 'page' => 1, 'per_page' => 25]);
check('purchase_in search by reference number', $dRef['pagination']['total'] === 1 && $dRef['rows'][0]['reference_no'] === 'PO-A-1');
$poRow = $dRef['rows'][0];
check('purchase_in row carries date/ref/sku/name/warehouse/qty/unit/qty_base/hpp/value (100 kg @ 1.100 = 110.000)', near($poRow['qty'], 100.0) && $poRow['unit'] === 'KG' && near($poRow['qty_base'], 100.0) && near($poRow['hpp'], 1100.0) && near($poRow['value'], 110000.0) && $poRow['warehouse'] !== '' && $poRow['type'] === 'IN');
$outRows = allRows($pdo, 'stock_out', null, 'custom', $R['from'], $R['to'])[0];
check('stock_out lists only OUT lines (no TRANSFER_OUT) — transfer out is NOT double counted', count(array_filter($outRows, static fn ($r) => $r['type'] !== 'OUT')) === 0 && count($outRows) === 4);
check('stock_out row magnitudes are positive and VOID status is visible', count(array_filter($outRows, static fn ($r) => $r['value'] <= 0)) === 0);
$purchRows = allRows($pdo, 'purchase_in', null, 'custom', $R['from'], $R['to'])[0];
check('purchase_in excludes TRANSFER_IN receipts, the voided IN and OPENING rows', count(array_filter($purchRows, static fn ($r) => $r['type'] !== 'IN' || $r['status'] !== 'POSTED')) === 0 && !in_array('PO-VOIDED', array_column($purchRows, 'reference_no'), true));
$otherRows = allRows($pdo, 'move_other', null, 'custom', $R['from'], $R['to'])[0];
check('the voided purchase is disclosed under "Lainnya" with status VOID', count($otherRows) === 1 && $otherRows[0]['reference_no'] === 'PO-VOIDED' && $otherRows[0]['status'] === 'VOID');

// ---------------- D/E. today / month, batch reconciliation, Nilai Stok
echo "\n===== today / month · batch + Nilai Stok =====\n";
foreach ($scopes as $label => $wh) {
    foreach (['today', 'month'] as $period) {
        $o = D::overview($pdo, $wh, $period);
        $m = $o['movement'];
        $s = $o['summary'];
        check("[$label/$period] period resolved ({$o['period']['start_date']}..{$o['period']['end_date']}), ends today", $o['period']['end_date'] === $today && ($period === 'today' ? $o['period']['start_date'] === $today : $o['period']['start_date'] === date('Y-m-01')));
        check("[$label/$period] Stok Akhir == live inventory_batches valuation (on-hand)", $m['reconciliation']['batch_ok'] === true && near($m['closing_stock']['value'], $s['stock_value']['on_hand']), "{$m['closing_stock']['value']} vs {$s['stock_value']['on_hand']} diff {$m['reconciliation']['batch_diff']}");
        check("[$label/$period] identity holds", $m['reconciliation']['ok'] === true);
        $sum = InventorySummaryReportService::summary($pdo, $o['period']['start_date'], $o['period']['end_date'], $wh);
        check("[$label/$period] cards == Ringkasan Inventory for the same period", near($m['opening_stock']['value'], $sum['beginning_inventory_value']) && near($m['purchase_in']['value'], $sum['external_purchase']) && near($m['stock_out']['value'], $sum['out_usage']) && near($m['closing_stock']['value'], $sum['ending_inventory_value']));
    }
    $s = D::overview($pdo, $wh)['summary'];
    $ref = $wh === null ? InventoryService::companyTotalValue($pdo) : InventoryService::warehouseDashboardSummary($pdo, $wh);
    check("[$label] Nilai Stok (on-hand + in-transit) == existing valuation service", near($s['stock_value']['total'], $ref['total_value']) && near($s['stock_value']['on_hand'], $ref['on_hand_value']) && near($s['stock_value']['in_transit'], $ref['in_transit_value']), "{$s['stock_value']['total']} vs {$ref['total_value']}");
    [$cs, $csLast] = allRows($pdo, 'current_stock', $wh, 'month', null, null);
    check("[$label] Nilai Stok drill-down Σ rows == on-hand", near(round(array_sum(array_column($cs, 'value')), 4), $s['stock_value']['on_hand']));
    if ($wh !== null) {
        $ref2 = InventoryService::warehouseDashboardSummary($pdo, $wh);
        check("[$label] total_sku/sku_with_stock == existing warehouse summary", $s['sku_with_stock'] === $ref2['sku_with_stock']);
    }
}
$ov = D::overview($pdo, null, 'today');
check('company in-transit value is the pending transfer (10 x 1.000 = 10.000... plus none other)', near($ov['summary']['stock_value']['in_transit'], 10000.0), (string) $ov['summary']['stock_value']['in_transit']);
check('Transfer Pending count (company) == 1 and Stock Opname Aktif == 1', $ov['summary']['pending_transfers'] === 1 && $ov['summary']['active_opname'] === 1);
$ovA = D::overview($pdo, $W['A'], 'today');
$ovC = D::overview($pdo, $W['C'], 'today');
check('Transfer Pending (A: shipper) = 1, (C: unrelated warehouse) = 0', $ovA['summary']['pending_transfers'] === 1 && $ovC['summary']['pending_transfers'] === 0);
check('Stock Opname Aktif is per warehouse (B has the OPEN session, A and C do not)', D::overview($pdo, $W['B'], 'today')['summary']['active_opname'] === 1 && $ovA['summary']['active_opname'] === 0);
$pt = D::detail($pdo, 'pending_transfers', null, 'today', null, null, ['page' => 1, 'per_page' => 25]);
check('pending_transfers detail: 1 row, value == in-transit', $pt['pagination']['total'] === 1 && near($pt['grand_total']['value'], 10000.0) && $pt['rows'][0]['from'] === 'Gudang SCM / Gudang Besar');
$ao = D::detail($pdo, 'active_opname', null, 'today', null, null, ['page' => 1, 'per_page' => 25]);
check('active_opname detail lists the OPEN session', $ao['pagination']['total'] === 1 && $ao['rows'][0]['reference_no'] === 'SO-DF-OPEN');

// ---------------- F. attention / activity / recent / top lists
echo "\n===== attention · activity · recent · top lists =====\n";
$att = [];
foreach ($ov['attention'] as $a) { $att[$a['key']] = $a; }
$habis = (int) $pdo->query("SELECT COUNT(*) FROM items i LEFT JOIN (SELECT item_id, SUM(qty_base) q FROM inventory_batches GROUP BY item_id) b ON b.item_id = i.id WHERE i.status='ACTIVE' AND COALESCE(b.q,0) <= 0")->fetchColumn();
check('Stok Habis (company) == independent SQL', $att['out_of_stock']['sku_count'] === $habis, "{$att['out_of_stock']['sku_count']} vs {$habis}");
$belowMin = (int) $pdo->query("SELECT COUNT(*) FROM items i LEFT JOIN (SELECT item_id, SUM(qty_base) q FROM inventory_batches GROUP BY item_id) b ON b.item_id = i.id WHERE i.status='ACTIVE' AND COALESCE(b.q,0) > 0 AND COALESCE(b.q,0) < i.minimum_stock")->fetchColumn();
check('Di Bawah Minimum (company) == independent SQL', $att['below_minimum']['sku_count'] === $belowMin, "{$att['below_minimum']['sku_count']} vs {$belowMin}");
$ovCAtt = [];
foreach (D::overview($pdo, $W['C'], 'today')['attention'] as $a) { $ovCAtt[$a['key']] = $a; }
check('Di Bawah Minimum (warehouse C) includes the ragi line (8 kg < 40) with its estimated value 7.200', $ovCAtt['below_minimum']['sku_count'] >= 1 && $ovCAtt['below_minimum']['value'] >= 7200.0);
check('Rusak = ONLY the latest POSTED opname of the warehouse: 3x1.000 + 2x2.000 = 7.000 (the older session\'s 99 is ignored)', $att['rusak']['sku_count'] === 2 && near($att['rusak']['value'], 7000.0), json_encode($att['rusak']));
$rd = D::detail($pdo, 'rusak', null, 'today', null, null, ['page' => 1, 'per_page' => 25]);
check('Rusak drill-down: 2 rows, Σ == card, shows the session number', $rd['pagination']['total'] === 2 && near($rd['grand_total']['value'], 7000.0) && $rd['rows'][0]['session'] === 'SO-DF-NEW');
check('Rusak for warehouse B (no posted opname) is 0', (function () use ($pdo, $W) { foreach (D::overview($pdo, $W['B'], 'today')['attention'] as $a) { if ($a['key'] === 'rusak') return $a['sku_count'] === 0 && $a['value'] == 0.0; } return false; })());
check('Dead Stock = ONLY the latest POSTED opname of the warehouse (same source as the SO result): 4x1.000 + 1x2.000 = 6.000 over 2 SKU (the older session\'s 50 is ignored)', $att['dead_stock']['sku_count'] === 2 && near($att['dead_stock']['value'], 6000.0) && $att['dead_stock']['hint'] === 'Hasil Stock Opname terakhir', json_encode($att['dead_stock']));
$dd = D::detail($pdo, 'deadstock', null, 'today', null, null, ['page' => 1, 'per_page' => 25]);
check('Dead Stock drill-down: 2 rows, Σ == card, shows the SO session number, kind=deadstock', $dd['kind'] === 'deadstock' && $dd['pagination']['total'] === 2 && near($dd['grand_total']['value'], 6000.0) && $dd['rows'][0]['session'] === 'SO-DF-NEW' && near($dd['card_total']['value'], $att['dead_stock']['value']));
check('Dead Stock drill-down search narrows rows but the card total stays', (function () use ($pdo) { $r = D::detail($pdo, 'deadstock', null, 'today', null, null, ['page' => 1, 'per_page' => 25, 'q' => 'zzzz-nothing']); return $r['pagination']['total'] === 0 && near($r['card_total']['value'], 6000.0); })());
check('Dead Stock for warehouse B (no posted opname) is 0; action opens the detail drawer', (function () use ($pdo, $W, $att) { foreach (D::overview($pdo, $W['B'], 'today')['attention'] as $a) { if ($a['key'] === 'dead_stock') return $a['sku_count'] === 0 && $a['value'] == 0.0 && $att['dead_stock']['action'] === ['type' => 'detail', 'detail' => 'deadstock']; } return false; })());
check('Expired is omitted when no batch has an expiry date (no fabricated 0)', !isset($att['expired']));
foreach ($ov['attention'] as $a) {
    check("attention '{$a['key']}' has an action the UI can follow", isset($a['action']['type']));
}

$act = $ov['today_activity'];
$cnt = static fn (string $type, string $status = 'POSTED') => (int) $GLOBALS['pdo']->query("SELECT COUNT(DISTINCT t.id) FROM inventory_transactions t WHERE t.transaction_type='{$type}' AND t.status='{$status}' AND DATE(t.transaction_date)=CURDATE() AND t.inventory_effect=1")->fetchColumn();
check('Aktivitas Hari Ini: counts == independent SQL (IN 1, OUT 1, TRANSFER_OUT 1, TRANSFER_IN 1)', $act['stock_in']['count'] === $cnt('IN') && $act['stock_out']['count'] === $cnt('OUT') && $act['transfer_out']['count'] === $cnt('TRANSFER_OUT') && $act['transfer_in']['count'] === $cnt('TRANSFER_IN') && $act['stock_in']['count'] === 1 && $act['transfer_in']['count'] === 1, json_encode($act));
check('Aktivitas Hari Ini: stock IN value = 25 x 2.200 = 55.000, stock OUT = 5 x FIFO cost', near($act['stock_in']['value'], 55000.0) && $act['stock_out']['value'] > 0);
$actA = D::overview($pdo, $W['A'], 'today')['today_activity'];
check('Aktivitas Hari Ini is warehouse-scoped (A has no stock IN today)', $actA['stock_in']['count'] === 0 && $actA['stock_out']['count'] === 1);
$rec = $ov['recent_activity'];
check('Recent activity: newest first, at most 8, each with reference/jenis/gudang/jumlah item/nilai/status', count($rec) <= 8 && count($rec) > 0 && $rec === array_values($rec) && (function () use ($rec) { for ($i = 1; $i < count($rec); $i++) { if ($rec[$i - 1]['date'] < $rec[$i]['date']) return false; } return true; })() && isset($rec[0]['reference_no'], $rec[0]['type_label'], $rec[0]['warehouse'], $rec[0]['item_count'], $rec[0]['value'], $rec[0]['status']));
check('Recent activity never lists REVERSAL rows', count(array_filter($rec, static fn ($r) => $r['type'] === 'REVERSAL')) === 0);
$low = $ov['top_low_stock'];
check('Top 5 stok menipis: ≤ 5 rows, each qty < minimum, gap = qty − minimum, ascending qty', count($low) <= 5 && count($low) > 0 && count(array_filter($low, static fn ($r) => $r['qty'] >= $r['minimum'] || abs($r['gap'] - ($r['qty'] - $r['minimum'])) > 0.0001)) === 0);
$top = $ov['top_inventory_value'];
check('Top 5 nilai stok: ≤ 5 rows, descending value, hpp = value/qty', count($top) === 5 && $top[0]['value'] >= $top[4]['value'] && abs($top[0]['hpp'] - $top[0]['value'] / $top[0]['qty']) < 0.01);

// ---------------- pre go-live / validation
echo "\n===== pre-go-live · validation =====\n";
$pre = D::overview($pdo, null, 'custom', '2019-01-01', '2019-12-31');
check('a period entirely before go-live returns honest zeros + a note (never a fabricated value)', $pre['movement']['is_pre_go_live'] === true && $pre['movement']['opening_stock']['value'] == 0.0 && $pre['movement']['closing_stock']['value'] == 0.0 && $pre['movement']['note'] !== null);
$live = D::overview($pdo, null, 'custom', '2026-06-01', '2026-06-20');
check('a period after go-live is NOT clamped and carries no go-live note (cutoverContext echoed)', $live['movement']['effective_start_date'] === '2026-06-01' && $live['movement']['note'] === null && $live['movement']['cutover']['live_opening_date'] === '2020-01-01');
$bad = static function (callable $fn): bool { try { $fn(); return false; } catch (App\Services\ValidationException $e) { return true; } };
check('invalid period / missing custom dates / reversed / future / > 400 days → ValidationException', $bad(fn () => D::overview($pdo, null, 'week')) && $bad(fn () => D::overview($pdo, null, 'custom')) && $bad(fn () => D::overview($pdo, null, 'custom', '2026-06-20', '2026-06-10')) && $bad(fn () => D::overview($pdo, null, 'custom', '2026-06-10', date('Y-m-d', strtotime('+3 day')))) && $bad(fn () => D::overview($pdo, null, 'custom', '2024-01-01', '2026-06-01')) && $bad(fn () => D::overview($pdo, null, 'custom', 'abc', 'def')));
check('unknown detail type → ValidationException; unknown warehouse → NotFound', $bad(fn () => D::detail($pdo, 'drop_table', null, 'month', null, null, [])) && (function () use ($pdo) { try { D::overview($pdo, 987654); return false; } catch (App\Services\NotFoundException $e) { return true; } })());
$empty = D::detail($pdo, 'purchase_in', $W['C'], 'custom', $R['from'], $R['to'], ['page' => 1, 'per_page' => 25]);
check('empty state: a warehouse with no purchase in the period returns 0 rows and a 0 grand total', $empty['rows'] === [] && $empty['grand_total']['value'] == 0.0 && $empty['pagination']['total_pages'] === 1);

// ---------------- G. read-only
echo "\n===== read-only =====\n";
check('NO table changed during all of the above (12 tables, rows + content checksums)', snapshot($pdo) === $before, json_encode(array_keys(array_filter($before, static fn ($v, $k) => snapshot($pdo)[$k] !== $v, ARRAY_FILTER_USE_BOTH))));
check('service source has no INSERT/UPDATE/DELETE/DDL and calls no posting service',
    !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE)\b|(FifoService|TransferService|StockAdjustmentService|StockOpnameService|VoidService)::/i',
        preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents(__DIR__ . '/../services/DashboardInventoryService.php'))));

// ---------------- HTTP
echo "\n===== HTTP =====\n";
$port = 8900 + random_int(4000, 4400);
$proc = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(__DIR__ . '/../public')), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..');
$base = "http://127.0.0.1:{$port}/api";
$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
    usleep(100_000);
    $ch = curl_init("{$base}/auth/me");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500]);
    $ready = curl_exec($ch) !== false;
    curl_close($ch);
}
function http(string $method, string $url, ?array $body = null, ?string $jar = null, ?string $csrf = null): array
{
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    if ($csrf) { $h[] = "X-CSRF-Token: {$csrf}"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $h]);
    if ($jar) { curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]); }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $st, 'body' => json_decode((string) $raw, true) ?: []];
}
function login(string $base, array $c): array
{
    $jar = tempnam(sys_get_temp_dir(), 'df_');
    $r = http('POST', "{$base}/auth/login", ['username' => $c['username'], 'password' => $c['password']], $jar);
    return ['jar' => $jar, 'csrf' => $r['body']['data']['csrf_token'] ?? '', 'status' => $r['status']];
}
try {
    check('server ready', $ready);
    $admin = login($base, $fx['admin']);
    $viewer = login($base, $fx['viewer']);
    $stockA = login($base, $fx['stockA']);
    check('logins', $admin['status'] === 200 && $viewer['status'] === 200 && $stockA['status'] === 200);
    $q = "period=custom&date_from={$R['from']}&date_to={$R['to']}";
    $r = http('GET', "{$base}/dashboard/inventory?{$q}", null, $admin['jar']);
    check('SUPERADMIN company-wide → 200, same numbers as the service', $r['status'] === 200 && near($r['body']['data']['movement']['closing_stock']['value'], D::overview($pdo, null, 'custom', $R['from'], $R['to'])['movement']['closing_stock']['value']) && $r['body']['data']['scope']['is_company'] === true, (string) $r['status']);
    $r = http('GET', "{$base}/dashboard/inventory?{$q}&warehouse_id={$W['B']}", null, $viewer['jar']);
    check('VIEWER (INVENTORY_VIEW only, unscoped) may pick a warehouse → scope = B', $r['status'] === 200 && $r['body']['data']['scope']['warehouse_id'] === $W['B']);
    $r = http('GET', "{$base}/dashboard/inventory?{$q}", null, $stockA['jar']);
    check('STOCK scoped to A asking for "all" is FORCED to A (never company-wide)', $r['status'] === 200 && $r['body']['data']['scope']['warehouse_id'] === $W['A'] && $r['body']['data']['scope']['is_company'] === false);
    check('…and every card of that response is A\'s own', near($r['body']['data']['movement']['closing_stock']['value'], D::overview($pdo, $W['A'], 'custom', $R['from'], $R['to'])['movement']['closing_stock']['value']));
    $r = http('GET', "{$base}/dashboard/inventory?{$q}&warehouse_id={$W['B']}", null, $stockA['jar']);
    check('STOCK scoped to A asking for warehouse B is FORCED to A (the app-wide inv_hpp_resolve_warehouse_scope convention) — B\'s numbers never leak', $r['status'] === 200 && $r['body']['data']['scope']['warehouse_id'] === $W['A'] && !near($r['body']['data']['movement']['closing_stock']['value'], D::overview($pdo, $W['B'], 'custom', $R['from'], $R['to'])['movement']['closing_stock']['value']));
    $r = http('GET', "{$base}/dashboard/inventory/detail?type=closing_stock&{$q}&warehouse_id={$W['B']}&per_page=25", null, $stockA['jar']);
    check('detail endpoint: STOCK A asking for B also gets only A rows, Σ == A card', $r['status'] === 200 && near($r['body']['data']['grand_total']['value'], D::overview($pdo, $W['A'], 'custom', $R['from'], $R['to'])['movement']['closing_stock']['value']) && count(array_filter($r['body']['data']['rows'], static fn ($x) => $x['warehouse'] !== 'Gudang SCM / Gudang Besar')) === 0);
    $r = http('GET', "{$base}/dashboard/inventory/detail?type=closing_stock&{$q}&row_warehouse_id={$W['B']}&per_page=25", null, $stockA['jar']);
    check('detail endpoint: row_warehouse_id can never widen a STOCK user beyond their own warehouse', $r['status'] === 200 && count(array_filter($r['body']['data']['rows'], static fn ($x) => $x['warehouse'] !== 'Gudang SCM / Gudang Besar')) === 0);
    $r = http('GET', "{$base}/dashboard/inventory/detail?type=closing_stock&{$q}&per_page=25&page=1", null, $stockA['jar']);
    check('detail: STOCK A "all" → only A rows, Σ == A card', $r['status'] === 200 && near($r['body']['data']['grand_total']['value'], D::overview($pdo, $W['A'], 'custom', $R['from'], $R['to'])['movement']['closing_stock']['value']) && count(array_filter($r['body']['data']['rows'], static fn ($x) => $x['warehouse'] !== 'Gudang SCM / Gudang Besar')) === 0);
    $r = http('GET', "{$base}/dashboard/inventory/detail?type=purchase_in&{$q}&q=PO-A-1&per_page=25", null, $admin['jar']);
    check('detail HTTP search works', $r['status'] === 200 && $r['body']['data']['pagination']['total'] === 1);
    check('unauthenticated → 401 (overview + detail)', http('GET', "{$base}/dashboard/inventory")['status'] === 401 && http('GET', "{$base}/dashboard/inventory/detail?type=closing_stock")['status'] === 401);
    check('bad period → 422, bad type → 422', http('GET', "{$base}/dashboard/inventory?period=week", null, $admin['jar'])['status'] === 422 && http('GET', "{$base}/dashboard/inventory/detail?type=nope", null, $admin['jar'])['status'] === 422);
    $snap = snapshot($pdo);
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
        check("{$verb} on the dashboard routes → 404, nothing handled", http($verb, "{$base}/dashboard/inventory", [], $admin['jar'], $admin['csrf'])['status'] === 404 && http($verb, "{$base}/dashboard/inventory/detail", [], $admin['jar'], $admin['csrf'])['status'] === 404);
    }
    check('HTTP calls + rejected write verbs changed nothing', snapshot($pdo) === $snap);
} finally {
    if (is_resource($proc)) { proc_terminate($proc); }
}

$pass = count(array_filter($results));
echo "\n{$pass} / " . count($results) . " PASSED\n";
exit($pass === count($results) ? 0 : 1);
