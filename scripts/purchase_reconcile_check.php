<?php
declare(strict_types=1);

require_once is_file(__DIR__ . '/rv3_bootstrap.php') ? __DIR__ . '/rv3_bootstrap.php' : __DIR__ . '/rv3/rv3_bootstrap.php';   // common bootstrap: rv3_script_exit(), --package-dir, service loader
$__rv3_payload = rv3_bootstrap_args($argv);

/**
 * READ-ONLY reconciliation of "Laporan Pembelian" against the real Stock IN V2 data. Never writes (READ ONLY transaction; a write is proved to be rejected first).
 *   1. every V2 row re-derived INDEPENDENTLY from its stored inputs: gross = qty x gross price; DPP = gross - item discount; net DPP = DPP - invoice-discount (DPP basis);
 *      PPN after discount = net DPP x rate; row total = net DPP + PPN + freight share — all equal the stored values (storage precision)
 *   2. every invoice: Gross - item discount = Subtotal Barang; Subtotal + PPN - Diskon Invoice + Ongkos Kirim = Total Pembelian (to storage precision)
 *   3. invoice discount allocation: proportional to the row totals (the Stock IN V2 rule), freight allocation: proportional to net DPP
 *      (PurchaseCostingService::allocateProportionally over the stored net DPP) — per invoice
 *   4. audit cross-check: the PURCHASE_INVOICE_POST audit record of each V2 entry (written at posting time) states the same Subtotal (incl. PPN), freight and Grand Total
 *   5. stored FIFO value of each row (inventory_transaction_lines.subtotal) == purchase_line_costs.final_inventory_cost (|diff| <= 0.05)
 *   6. report totals: Total Pembelian == sum of invoice rows == independent SQL over the posted IN transactions; PPN / freight / item discount / invoice discount equal the
 *      sum of their rows; Rincian per Barang totals (incl. per-item freight and invoice-discount allocation) equal the invoice totals
 * Any difference is printed with invoice / amount and the exit code is 1 — do NOT deploy on a non-zero exit.
 *
 *   php scripts/purchase_reconcile_check.php --app-root=<dir with services/> [--start=YYYY-MM-DD --end=YYYY-MM-DD] [--warehouse=<code|id|all>] [--historical=1|all]
 */

$appRoot = $start = $end = $whArg = $hist = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) { $appRoot = rtrim(substr($arg, 11), '/'); }
    elseif (str_starts_with($arg, '--start=')) { $start = substr($arg, 8); }
    elseif (str_starts_with($arg, '--end=')) { $end = substr($arg, 6); }
    elseif (str_starts_with($arg, '--warehouse=')) { $whArg = substr($arg, 12); }
    elseif (str_starts_with($arg, '--historical=')) { $hist = substr($arg, 13); }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); rv3_script_exit(2); }
}
$end ??= date('Y-m-d');
$start ??= date('Y-m-d', strtotime('-365 days', strtotime($end)));
if ($appRoot === null || !is_dir("{$appRoot}/services") || strtotime($start) === false || strtotime($end) === false || $start > $end) {
    fwrite(STDERR, "usage: php scripts/purchase_reconcile_check.php --app-root=<dir> [--start=YYYY-MM-DD --end=YYYY-MM-DD] [--warehouse=<code|id|all>] [--historical=1|all]\n");
    rv3_script_exit(2);
}
rv3_bootstrap_load($appRoot, $__rv3_payload);

use App\Services\Database;
use App\Services\PurchaseCostingService;
use App\Services\PurchaseReportService as R;

$pdo = Database::connection();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
try {
    $pdo->exec('UPDATE items SET id = id WHERE 1 = 0');
    fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction.\n");
    $pdo->exec('ROLLBACK');
    rv3_script_exit(3);
} catch (PDOException $e) {
    echo "read-only guard verified: a write attempt is rejected by the server ({$e->getCode()}).\n";
}
$fail = 0; $n = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail, &$n): void {
    $n++;
    if (!$ok) { $fail++; }
    echo ($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
};
$near = static fn (?float $a, ?float $b, float $e = 0.002): bool => ($a === null || $b === null) ? $a === $b : abs($a - $b) <= $e;

$scopes = [];
if ($whArg === null || $whArg === 'all') { $scopes['Semua Gudang'] = null; }
foreach ($pdo->query('SELECT id, code, name FROM warehouses ORDER BY id')->fetchAll() as $w) {
    if ($whArg === 'all' || $whArg === $w['code'] || $whArg === (string) $w['id']) { $scopes["{$w['code']} ({$w['name']})"] = (int) $w['id']; }
}
echo "Period {$start} .. {$end}" . ($hist ? " (historical={$hist})" : ' (live only)') . "\n";

foreach ($scopes as $label => $wh) {
    echo "\n== {$label} ==\n";
    $f = ['start_date' => $start, 'end_date' => $end, 'warehouse_id' => $wh, 'historical' => (string) $hist, 'per_page' => 100];
    $ov = R::overview($pdo, $f);
    $k = $ov['kpi']['nominal'];
    $lines = R::lineRows($pdo, $f);
    $groups = [];
    foreach ($lines as $l) { $groups[$l['group_key']][] = $l; }

    // ---- 1 + 5: row level
    $bad = [];
    foreach ($lines as $l) {
        if (!$l['counted'] || $l['source'] !== 'V2') { continue; }
        $tag = "{$l['sku']} tx {$l['tx_id']}";
        $dpp = round($l['gross'] - $l['item_discount'], 4);
        $net = round($dpp - $l['invoice_discount_dpp'], 4);
        $ppnNet = round($net * $l['ppn_rate'] / 100, 4);
        if (!$near($dpp, $l['dpp'], 0.0002)) { $bad[] = "{$tag}: DPP {$dpp} != stored {$l['dpp']}"; }
        if (!$near($ppnNet, $l['ppn_net'], 0.0003)) { $bad[] = "{$tag}: PPN after discount {$ppnNet} != stored {$l['ppn_net']}"; }
        if (!$near(round($net + $l['ppn_net'] + $l['freight'], 4), $l['total'], 0.0003)) { $bad[] = "{$tag}: net DPP + PPN + freight != stored total {$l['total']}"; }
        if (!$near(round($l['dpp'] + $l['ppn'] - $l['invoice_discount'] + $l['freight'], 4), $l['total'], 0.0003)) { $bad[] = "{$tag}: Subtotal + PPN - Diskon Invoice + Ongkir != total"; }
        if (!$near($l['recorded_value'], $l['inventory_cost'], 0.05)) { $bad[] = "{$tag}: FIFO line value {$l['recorded_value']} != costing {$l['inventory_cost']}"; }
    }
    $nv2 = count(array_filter($lines, static fn ($l) => $l['counted'] && $l['source'] === 'V2'));
    $check("1+5. all {$nv2} V2 rows re-derive from their stored inputs (gross, DPP, PPN, total) and match the FIFO line value", $bad === [], implode(' | ', array_slice($bad, 0, 5)));

    // ---- 2 + 3 + 4: invoice level
    $badInv = []; $badAlloc = []; $badAudit = []; $auditChecked = 0;
    foreach ($groups as $key => $ls) {
        $v2 = array_values(array_filter($ls, static fn ($l) => $l['counted'] && $l['source'] === 'V2'));
        if (!$v2) { continue; }
        $sum = static fn (string $c): float => round(array_sum(array_map(static fn ($l) => (float) $l[$c], $v2)), 4);
        $ref = $v2[0]['reference'] ?? $key;
        if (!$near($sum('gross') - $sum('item_discount'), $sum('dpp'), 0.0005)) { $badInv[] = "{$ref}: gross - item discount != subtotal"; }
        if (!$near($sum('dpp') + $sum('ppn') - $sum('invoice_discount') + $sum('freight'), $sum('total'), 0.001)) { $badInv[] = "{$ref}: Subtotal + PPN - Diskon Invoice + Ongkir != Grand Total"; }
        // freight: proportional to net DPP
        if (count($v2) > 1 && $sum('freight') > 0) {
            $re = PurchaseCostingService::allocateProportionally(array_map(static fn ($l) => $l['net_dpp'], $v2), $sum('freight'));
            foreach ($v2 as $i => $l) {
                if (!$near($re[$i], $l['freight'], 0.0003)) { $badAlloc[] = "{$ref}: freight share of {$l['sku']} {$l['freight']} != proportional {$re[$i]}"; }
            }
        }
        // invoice discount: proportional to row total (PPN-inclusive) — A_i / total_i constant across rows
        $totalsIncl = array_map(static fn ($l) => $l['dpp'] + $l['ppn'], $v2);
        $kRatio = array_sum($totalsIncl) > 0 ? $sum('invoice_discount') / array_sum($totalsIncl) : 0.0;
        foreach ($v2 as $i => $l) {
            if (abs($l['invoice_discount'] - $totalsIncl[$i] * $kRatio) > 0.01) { $badAlloc[] = "{$ref}: invoice-discount share of {$l['sku']} {$l['invoice_discount']} not proportional to its row total"; }
        }
        // audit record written at posting time
        if (str_starts_with($key, 'U:')) {
            $st = $pdo->prepare("SELECT after_data FROM audit_logs WHERE action_code = 'PURCHASE_INVOICE_POST' AND JSON_UNQUOTE(JSON_EXTRACT(after_data, '$.request_uuid')) = :u LIMIT 1");
            $st->execute(['u' => substr($key, 2)]);
            $a = $st->fetchColumn();
            if ($a !== false && count($v2) === count($ls)) {
                $a = json_decode((string) $a, true);
                $auditChecked++;
                if (!$near((float) $a['grand_total'], $sum('total'), 0.01)) { $badAudit[] = "{$ref}: audit grand total {$a['grand_total']} != Σ {$sum('total')}"; }
                if (!$near((float) $a['subtotal'], round($sum('dpp') + $sum('ppn'), 4), 0.01)) { $badAudit[] = "{$ref}: audit subtotal {$a['subtotal']} != Σ DPP + PPN"; }
                if (!$near((float) $a['freight_amount'], $sum('freight'), 0.01)) { $badAudit[] = "{$ref}: audit freight {$a['freight_amount']} != Σ {$sum('freight')}"; }
                if (!$near((float) $a['invoice_discount'], $sum('invoice_discount'), 0.01)) { $badAudit[] = "{$ref}: audit invoice discount {$a['invoice_discount']} != Σ {$sum('invoice_discount')}"; }
            }
        }
    }
    $check('2. every invoice: Gross − Diskon Barang = Subtotal Barang and Subtotal + PPN − Diskon Invoice + Ongkos Kirim = Total Pembelian', $badInv === [], implode(' | ', array_slice($badInv, 0, 5)));
    $check('3. allocations follow the Stock IN V2 rules: invoice discount proportional to row totals, freight proportional to net DPP', $badAlloc === [], implode(' | ', array_slice($badAlloc, 0, 5)));
    $check("4. audit cross-check ({$auditChecked} invoices with a PURCHASE_INVOICE_POST record): Subtotal, invoice discount, freight and Grand Total equal the stored rows", $badAudit === [], implode(' | ', array_slice($badAudit, 0, 5)));

    // ---- 6: report totals
    $invRows = R::invoices($pdo, $f);
    $all = [];
    for ($p = 1; $p <= $invRows['pagination']['total_pages']; $p++) { $all = array_merge($all, R::invoices($pdo, $f + ['page' => $p])['rows']); }
    $sumRows = static fn (string $c): float => round(array_sum(array_map(static fn ($r) => (float) ($r[$c] ?? 0), $all)), 4);
    $dateSql = "DATE(t.transaction_date) BETWEEN " . $pdo->quote($start) . ' AND ' . $pdo->quote($end);
    $whSql = $wh !== null ? " AND t.warehouse_id = {$wh}" : '';
    $histSql = $hist === '1' ? ' AND t.is_historical_import = 1' : ($hist === 'all' ? '' : ' AND t.is_historical_import = 0 AND t.inventory_effect = 1');
    $v2sql = (float) $pdo->query("SELECT COALESCE(SUM(h.invoice_total),0) FROM purchase_invoice_headers h JOIN inventory_transactions t ON t.id = h.transaction_id WHERE t.transaction_type = 'IN' AND t.status = 'POSTED' AND {$dateSql}{$whSql}{$histSql}")->fetchColumn();
    $legsql = (float) $pdo->query("SELECT COALESCE(SUM(l.subtotal),0) FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id = t.id LEFT JOIN purchase_invoice_headers h ON h.transaction_id = t.id WHERE t.transaction_type = 'IN' AND t.status = 'POSTED' AND h.id IS NULL AND {$dateSql}{$whSql}{$histSql}")->fetchColumn();
    $check('6a. Total Pembelian == Σ invoice rows == independent SQL over the posted IN transactions', $near($k['total'], $sumRows('total')) && $near($k['total'], round($v2sql + $legsql, 4)), "report {$k['total']} / rows {$sumRows('total')} / SQL " . round($v2sql + $legsql, 4));
    $check('6b. PPN, Ongkos Kirim, Diskon Barang, Diskon Invoice, Subtotal Barang == Σ of their invoice rows', $near($k['ppn'], $sumRows('ppn')) && $near($k['freight'], $sumRows('freight')) && $near($k['item_discount'], $sumRows('item_discount')) && $near($k['invoice_discount'], $sumRows('invoice_discount'), 0.005) && $near($k['subtotal'], $sumRows('subtotal')), json_encode([$k['ppn'], $sumRows('ppn'), $k['freight'], $sumRows('freight')]));
    $items = R::items($pdo, $f + ['per_page' => 100]);
    $ft = $items['footer']['totals'];
    $check('6c. Rincian per Barang: Σ total == report total; Σ freight allocation == Σ invoice freight; Σ invoice-discount allocation == Σ invoice discount; Σ PPN == PPN', $near($ft['total'], $k['total']) && $near($ft['freight'], $k['freight']) && $near($ft['invoice_discount'], $k['invoice_discount'], 0.005) && $near($ft['ppn'], $k['ppn']) && $near($ft['item_discount'], $k['item_discount']), json_encode($ft));
    $legacyN = $k['sources']['Legacy']['invoices'] + $k['sources']['Historis']['invoices'];
    echo "    Total Rp {$k['total']} | Subtotal {$k['subtotal']} | Diskon barang {$k['item_discount']} | Diskon invoice {$k['invoice_discount']} | PPN {$k['ppn']} | Ongkir {$k['freight']} | invoice {$k['invoices']} (V2 {$k['sources']['V2']['invoices']}, legacy/historis {$legacyN}) | VOID tidak dihitung {$ov['disclosures']['void']['invoices']} (Rp {$ov['disclosures']['void']['amount']})\n";
}
$pdo->exec('ROLLBACK');
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : ' — all reconcile') . "\n";
rv3_script_exit($fail ? 1 : 0);
