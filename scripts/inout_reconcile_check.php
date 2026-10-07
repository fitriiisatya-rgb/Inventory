<?php
declare(strict_types=1);

if (!class_exists('RV3ScriptExit', false)) { final class RV3ScriptExit extends RuntimeException {} }
if (!function_exists('rv3_script_exit')) { function rv3_script_exit(int $c): never { if (defined('RV3_INPROCESS')) { throw new RV3ScriptExit('exit', $c); } exit($c); } }   // run in-process by the package validator: no child process, no shell

/**
 * READ-ONLY reconciliation of "Laporan IN / OUT / Transfer" against the real ledger. Never writes (a READ ONLY transaction; a write is proved to be rejected first).
 * Per warehouse scope it runs the service's own checks (InOutReportService::reconcile) AND three independent raw-SQL checks that do not use the report code:
 *   IN        Grand Total = Subtotal + PPN − Diskon Invoice + Ongkir (per Stock IN V2 invoice) · KPI = table footer
 *   OUT       Σ FIFO allocation qty = qty OUT and Σ FIFO value = stored HPP (every OUT line) · Σ Nilai Jual = invoice subtotal, + shipping = grand total · derived shipping allocation = header
 *   TRANSFER  TRANSFER_OUT value = TRANSFER_IN value for every RECEIVED transfer · received qty = sent qty · out = in + in-transit (company-wide net zero)
 *   RAW       (1) every OUT line of the period: Σ fifo_allocations.qty_allocated = base_qty  (2) every ISSUED distribution invoice: grand_total = subtotal − discount + tax + shipping
 *             (3) every RECEIVED transfer line: the TRANSFER_IN line value = the TRANSFER_OUT layer value it carries
 * Any difference is printed with its id / amount and the exit code is 1 — do NOT deploy on a non-zero exit.
 *
 *   php scripts/inout_reconcile_check.php --app-root=<dir with services/> [--start=YYYY-MM-DD --end=YYYY-MM-DD] [--warehouse=<code|id|all>]
 */

$appRoot = $start = $end = $whArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) { $appRoot = rtrim(substr($arg, 11), '/'); }
    elseif (str_starts_with($arg, '--start=')) { $start = substr($arg, 8); }
    elseif (str_starts_with($arg, '--end=')) { $end = substr($arg, 6); }
    elseif (str_starts_with($arg, '--warehouse=')) { $whArg = substr($arg, 12); }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); rv3_script_exit(2); }
}
$end ??= date('Y-m-d');
$start ??= date('Y-m-01', strtotime($end));
if ($appRoot === null || !is_dir("{$appRoot}/services") || strtotime($start) === false || strtotime($end) === false || $start > $end) {
    fwrite(STDERR, "usage: php scripts/inout_reconcile_check.php --app-root=<dir> [--start=YYYY-MM-DD --end=YYYY-MM-DD] [--warehouse=<code|id|all>]\n");
    rv3_script_exit(2);
}
if (!defined('RV3_INPROCESS')) {   // in-process (package validator) the services are already loaded: the installed ones, with the package's substituted in memory
    foreach (glob("{$appRoot}/services/*.php") ?: [] as $f) { if (basename($f) !== 'ReportsV3Routes.php') { require_once $f; } }
}

use App\Services\Database;
use App\Services\InOutReportService as S;

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
$report = static function (string $name, bool $ok, string $detail) use (&$fail, &$n): void {
    $n++;
    if (!$ok) { $fail++; }
    echo ($ok ? 'PASS' : 'FAIL') . " - {$name} ({$detail})\n";
};
$scopes = [];
if ($whArg === null || $whArg === 'all') { $scopes['Semua Gudang'] = null; }
foreach ($pdo->query('SELECT id, code, name FROM warehouses ORDER BY id')->fetchAll() as $w) {
    if ($whArg === 'all' || $whArg === $w['code'] || $whArg === (string) $w['id']) { $scopes["{$w['code']} ({$w['name']})"] = (int) $w['id']; }
}
echo "Period {$start} .. {$end}\n";
foreach ($scopes as $label => $wh) {
    echo "\n== {$label} ==\n";
    $f = ['start_date' => $start, 'end_date' => $end, 'warehouse_id' => $wh];
    foreach (S::reconcile($pdo, $f) as [$name, $ok, $detail]) {
        $report($name, $ok, $detail);
    }
    $in = S::inOverview($pdo, $f)['kpi']['nominal'];
    $out = S::outOverview($pdo, $f)['kpi']['nominal'];
    $tr = S::trfOverview($pdo, $f)['kpi']['nominal'];
    echo "    IN: nilai masuk {$in['total']} ({$in['invoices']} transaksi, {$in['skus']} SKU, {$in['suppliers']} supplier) · PPN {$in['ppn']} · ongkir {$in['freight']}\n";
    echo "    OUT: HPP {$out['hpp']} · nilai jual {$out['sell']} · ongkir {$out['shipping']} · margin {$out['margin']} ({$out['documents']} transaksi; HPP tanpa nilai jual {$out['hpp_without_sell']})\n";
    echo "    TRANSFER: {$tr['transfers']} aktif ({$tr['pending']} pending, {$tr['received']} diterima) · nilai cost {$tr['value']} · dalam perjalanan {$tr['in_transit_value']} · lead time rata-rata " . ($tr['avg_lead_hours'] ?? '—') . " jam\n";

    // ---- RAW 1: FIFO allocations vs OUT qty (independent SQL)
    $sql = "SELECT l.id, t.id AS tx, l.base_qty, COALESCE(SUM(fa.qty_allocated),0) AS alloc, l.subtotal, COALESCE(SUM(fa.subtotal),0) AS alloc_value
              FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id = t.id LEFT JOIN fifo_allocations fa ON fa.transaction_line_id = l.id
             WHERE t.transaction_type = 'OUT' AND t.status = 'POSTED' AND t.is_historical_import = 0 AND t.inventory_effect = 1 AND DATE(t.transaction_date) BETWEEN :a AND :b"
        . ($wh !== null ? ' AND t.warehouse_id = ' . (int) $wh : '') . ' GROUP BY l.id, t.id, l.base_qty, l.subtotal';
    $st = $pdo->prepare($sql);
    $st->execute(['a' => $start, 'b' => $end]);
    $bad = [];
    $cnt = 0;
    foreach ($st->fetchAll() as $r) {
        $cnt++;
        if (abs((float) $r['base_qty'] - (float) $r['alloc']) > 0.000001 || abs((float) $r['subtotal'] - (float) $r['alloc_value']) > 0.01) { $bad[] = "tx {$r['tx']} line {$r['id']}: qty {$r['base_qty']} vs {$r['alloc']}, HPP {$r['subtotal']} vs {$r['alloc_value']}"; }
    }
    $report('RAW1 every OUT line: Σ fifo_allocations qty = qty OUT and Σ value = stored HPP', $bad === [], "{$cnt} baris" . ($bad ? ' — ' . implode('; ', array_slice($bad, 0, 5)) : ''));

    // ---- RAW 2: ISSUED invoice header arithmetic
    $sql = "SELECT inv.id, inv.invoice_number, inv.subtotal, inv.discount_amount, inv.tax_amount, inv.shipping_amount, inv.grand_total, COALESCE(SUM(il.subtotal),0) AS lines_sum
              FROM distribution_invoices inv JOIN distribution_orders d ON d.id = inv.do_id LEFT JOIN distribution_invoice_lines il ON il.invoice_id = inv.id
             WHERE inv.status = 'ISSUED' AND d.status <> 'CANCELLED' AND inv.invoice_date BETWEEN :a AND :b" . ($wh !== null ? ' AND d.from_warehouse_id = ' . (int) $wh : '')
        . ' GROUP BY inv.id, inv.invoice_number, inv.subtotal, inv.discount_amount, inv.tax_amount, inv.shipping_amount, inv.grand_total';
    $st = $pdo->prepare($sql);
    $st->execute(['a' => $start, 'b' => $end]);
    $bad = [];
    $cnt = 0;
    foreach ($st->fetchAll() as $r) {
        $cnt++;
        if (abs((float) $r['lines_sum'] - (float) $r['subtotal']) > 0.01 || abs((float) $r['subtotal'] - (float) $r['discount_amount'] + (float) $r['tax_amount'] + (float) $r['shipping_amount'] - (float) $r['grand_total']) > 0.01) {
            $bad[] = "{$r['invoice_number']}: Σ baris {$r['lines_sum']}, subtotal {$r['subtotal']}, ongkir {$r['shipping_amount']}, grand {$r['grand_total']}";
        }
    }
    $report('RAW2 every ISSUED invoice: Σ line subtotal = subtotal and subtotal − diskon + pajak + ongkir = grand total', $bad === [], "{$cnt} invoice" . ($bad ? ' — ' . implode('; ', array_slice($bad, 0, 5)) : ''));

    // ---- RAW 3: transfer in-line value = out layer value
    $sql = "SELECT wt.id, SUM(il.subtotal) AS in_v, SUM(wtl.qty_base) AS q, SUM(il.base_qty) AS in_q
              FROM warehouse_transfers wt JOIN warehouse_transfer_lines wtl ON wtl.transfer_id = wt.id
              JOIN inventory_transaction_lines ol ON ol.id = wtl.out_transaction_line_id JOIN inventory_transaction_lines il ON il.id = wtl.in_transaction_line_id
             WHERE wt.status = 'RECEIVED' AND DATE(wt.ship_date) BETWEEN :a AND :b" . ($wh !== null ? ' AND (wt.from_warehouse_id = ' . (int) $wh . ' OR wt.to_warehouse_id = ' . (int) $wh . ')' : '') . ' GROUP BY wt.id';
    $st = $pdo->prepare($sql);
    $st->execute(['a' => $start, 'b' => $end]);
    $bad = [];
    $cnt = 0;
    foreach ($st->fetchAll() as $r) {
        $cnt++;
        // compare the layer value carried by the transfer lines (qty x layer cost) with what the TRANSFER_IN lines booked, and the quantities
        $lv = $pdo->prepare('SELECT SUM(qty_base * unit_cost_base) FROM warehouse_transfer_lines WHERE transfer_id = :i');
        $lv->execute(['i' => $r['id']]);
        $layer = (float) $lv->fetchColumn();
        if (abs($layer - (float) $r['in_v']) > 0.02 || abs((float) $r['q'] - (float) $r['in_q']) > 0.000001) { $bad[] = "TRF-{$r['id']}: layer {$layer} vs masuk {$r['in_v']}, qty {$r['q']} vs {$r['in_q']}"; }
    }
    $report('RAW3 every RECEIVED transfer: TRANSFER_IN value = the layer cost carried by TRANSFER_OUT', $bad === [], "{$cnt} transfer" . ($bad ? ' — ' . implode('; ', array_slice($bad, 0, 5)) : ''));
}
$pdo->exec('ROLLBACK');
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : ' — all reconcile') . "\n";
rv3_script_exit($fail ? 1 : 0);
