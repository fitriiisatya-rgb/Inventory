<?php
declare(strict_types=1);

/**
 * READ-ONLY reconciliation of the Jejak Stock Opname figures for real
 * sessions (e.g. production sessions 11 and 12) — run it on the host that
 * holds the database, with the application's normal .env/config.
 *
 *   php scripts/jejak_reconcile_sessions.php --app-root=/path/to/app [--service=/path/to/StockOpnameJejakService.php] [--json] <session_id> [<session_id> ...]
 *
 * It NEVER writes: the connection is switched to SET SESSION TRANSACTION
 * READ ONLY and the whole run happens inside one READ ONLY transaction that
 * is rolled back at the end — MySQL itself rejects any write attempt.
 *
 * For every session it prints the real identity, the line count, the six
 * KPIs, and then checks, each reported PASS/FAIL (exit code 1 if any FAIL):
 *   - every KPI value AND count equals the sum/count of its own drill-down
 *     rows (what the drawer shows)
 *   - every KPI equals an INDEPENDENT recomputation straight from the Stock
 *     Opname tables with plain SQL (LEGACY_DUAL_COUNT) or from
 *     StockOpnameBookStockService::reconciliation() (FINDINGS_V1)
 *   - the adjustments are the real stock_adjustments rows
 * --service defaults to <app-root>/services/StockOpnameJejakService.php
 * (point it at the package copy to reconcile BEFORE deploying the backend).
 */

$appRoot = null;
$servicePath = null;
$asJson = false;
$ids = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) {
        $appRoot = rtrim(substr($arg, 11), '/');
    } elseif (str_starts_with($arg, '--service=')) {
        $servicePath = substr($arg, 10);
    } elseif ($arg === '--json') {
        $asJson = true;
    } elseif (ctype_digit($arg)) {
        $ids[] = (int) $arg;
    } else {
        fwrite(STDERR, "unknown argument: {$arg}\n");
        exit(2);
    }
}
if ($appRoot === null || !is_dir("{$appRoot}/services") || $ids === []) {
    fwrite(STDERR, "usage: php scripts/jejak_reconcile_sessions.php --app-root=<dir with services/> [--service=<file>] [--json] <session_id> ...\n");
    exit(2);
}
$servicePath ??= "{$appRoot}/services/StockOpnameJejakService.php";

foreach (['Database.php', 'Exceptions.php', 'StockOpnameBookStockService.php'] as $f) {
    if (is_file("{$appRoot}/services/{$f}")) {
        require_once "{$appRoot}/services/{$f}";
    }
}
if (!is_file($servicePath)) {
    fwrite(STDERR, "service file not found: {$servicePath}\n");
    exit(2);
}
require_once $servicePath;

use App\Services\Database;
use App\Services\StockOpnameJejakService;

$pdo = Database::connection();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');

$failures = 0;
$report = [];
// Prove the guard itself before trusting it: a (zero-row) write must be REJECTED by the server.
try {
    $pdo->exec('UPDATE stock_opname_sessions SET id = id WHERE 1 = 0');
    fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction — refusing to continue.\n");
    $pdo->exec('ROLLBACK');
    exit(3);
} catch (PDOException $e) {
    echo $asJson ? '' : "read-only guard verified: a write attempt is rejected by the server ({$e->getCode()}).\n";
}
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures, &$current, $asJson): void {
    $current['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures++;
    }
    if (!$asJson) {
        echo '  ' . ($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
};
$near = static fn (float $a, float $b, float $eps = 0.02): bool => abs($a - $b) <= $eps;
$rp = static fn (float $v): string => 'Rp ' . number_format($v, 2, ',', '.');

foreach ($ids as $sid) {
    $current = ['session_id' => $sid, 'checks' => []];
    if (!$asJson) {
        echo "\n=== Session #{$sid} ===\n";
    }
    try {
        $d = StockOpnameJejakService::detail($pdo, $sid);
    } catch (Throwable $e) {
        $current['error'] = $e->getMessage();
        $failures++;
        echo $asJson ? '' : "  FAIL - could not build the Jejak: {$e->getMessage()}\n";
        $report[] = $current;
        continue;
    }
    $s = $d['session'];
    $items = $d['items'];
    $current['identity'] = ['session_number' => $s['session_number'], 'warehouse' => "{$s['warehouse_code']} — {$s['warehouse_name']}", 'status' => $s['status'], 'counting_model' => $s['counting_model'], 'session_date' => $s['session_date'], 'lines' => count($items), 'adjustments' => count($d['adjustments'])];
    $current['kpi'] = $d['kpi'];
    $current['data_quality'] = $d['data_quality'];
    if (!$asJson) {
        echo "  {$s['session_number']} · {$s['warehouse_code']} — {$s['warehouse_name']} · {$s['status']} · {$s['counting_model']} · {$s['session_date']}\n";
        echo '  lines: ' . count($items) . ' · posted adjustments: ' . count($d['adjustments']) . "\n";
        foreach ($d['kpi'] as $k => $v) {
            echo sprintf("  KPI %-18s %s  (%d)\n", $k, $rp((float) $v['value']), $v['count']);
        }
        foreach ($d['data_quality']['notes'] as $n) {
            echo "  note: {$n}\n";
        }
    }

    // ---- drill-down reconciliation (the rows the drawer lists)
    $sum = static fn (array $rows, string $k): float => round(array_sum(array_map(static fn ($r) => (float) $r[$k], $rows)), 2);
    $rows = [
        'nilai_stok_sistem' => array_filter($items, static fn ($i) => $i['system_value'] !== null),
        'nilai_final_count' => array_filter($items, static fn ($i) => $i['final_value'] !== null),
        'selisih_nominal' => array_filter($items, static fn ($i) => $i['variance_qty'] !== null && abs($i['variance_qty']) >= 0.0000001),
        'dead_stock' => array_filter($items, static fn ($i) => ($i['dead_qty'] ?? 0) > 0),
        'rusak' => array_filter($items, static fn ($i) => ($i['rusak_qty'] ?? 0) > 0),
    ];
    $field = ['nilai_stok_sistem' => 'system_value', 'nilai_final_count' => 'final_value', 'selisih_nominal' => 'variance_value', 'dead_stock' => 'dead_value', 'rusak' => 'rusak_value'];
    foreach ($rows as $k => $r) {
        $check("KPI {$k} = Σ of its drill-down rows and count = row count", $near($d['kpi'][$k]['value'], $sum($r, $field[$k]), 0.001) && $d['kpi'][$k]['count'] === count($r), $rp($d['kpi'][$k]['value']) . " / {$d['kpi'][$k]['count']} vs " . $rp($sum($r, $field[$k])) . ' / ' . count($r));
    }
    $check('KPI adjustment_bersih = Σ of adjustment rows and count = row count', $near($d['kpi']['adjustment_bersih']['value'], $sum($d['adjustments'], 'value'), 0.001) && $d['kpi']['adjustment_bersih']['count'] === count($d['adjustments']));

    // ---- independent recomputation
    $lineCount = (int) $pdo->query("SELECT COUNT(*) FROM stock_opname_lines WHERE session_id = {$sid}")->fetchColumn();
    $check('line count equals SELECT COUNT(*) FROM stock_opname_lines', $lineCount === count($items), "{$lineCount}");
    $sql = static fn (string $q): float => (float) $pdo->query($q)->fetchColumn();
    if ($s['counting_model'] === 'LEGACY_DUAL_COUNT') {
        $w = "session_id = {$sid}";
        $check('Nilai Stok Sistem = SQL Σ ROUND(system_qty_base × unit_cost_base, 2)', $near($d['kpi']['nilai_stok_sistem']['value'], $sql("SELECT COALESCE(SUM(ROUND(system_qty_base * unit_cost_base, 2)),0) FROM stock_opname_lines WHERE {$w}")));
        $check('Nilai Final Count = SQL Σ ROUND(counted_qty_base × unit_cost_base, 2)', $near($d['kpi']['nilai_final_count']['value'], $sql("SELECT COALESCE(SUM(ROUND(counted_qty_base * unit_cost_base, 2)),0) FROM stock_opname_lines WHERE {$w} AND counted_qty_base IS NOT NULL")));
        $check('Selisih Nominal = SQL Σ ROUND(variance_qty_base × unit_cost_base, 2) (non-excluded)', $near($d['kpi']['selisih_nominal']['value'], $sql("SELECT COALESCE(SUM(ROUND(variance_qty_base * unit_cost_base, 2)),0) FROM stock_opname_lines WHERE {$w} AND is_excluded = 0 AND variance_qty_base IS NOT NULL AND variance_qty_base <> 0")));
        $check('Dead Stock = SQL Σ ROUND(final_deadstock_qty × unit_cost_base, 2)', $near($d['kpi']['dead_stock']['value'], $sql("SELECT COALESCE(SUM(ROUND(final_deadstock_qty * unit_cost_base, 2)),0) FROM stock_opname_lines WHERE {$w} AND final_deadstock_qty > 0")));
        $check('Rusak = SQL Σ ROUND(final_rusak_qty × unit_cost_base, 2)', $near($d['kpi']['rusak']['value'], $sql("SELECT COALESCE(SUM(ROUND(final_rusak_qty * unit_cost_base, 2)),0) FROM stock_opname_lines WHERE {$w} AND final_rusak_qty > 0")));
    } else {
        $recon = StockOpnameBookStockService_reconciliation($pdo, $sid);
        $costBySku = [];
        foreach ($pdo->query("SELECT i.sku, sol.unit_cost_base FROM stock_opname_lines sol JOIN items i ON i.id = sol.item_id WHERE sol.session_id = {$sid}")->fetchAll() as $r) {
            $costBySku[$r['sku']] = (float) $r['unit_cost_base'];
        }
        $book = 0.0;
        foreach ($recon as $r) {
            $book += $r['book_stock_eod'] === null ? 0.0 : round($r['book_stock_eod'] * $costBySku[$r['sku']], 2);
        }
        $check('Nilai Stok Sistem = Σ ROUND(book_stock_eod × unit_cost_base, 2) from reconciliation()', $near($d['kpi']['nilai_stok_sistem']['value'], $book));
        $check('Selisih Nominal: every row variance = (final GOOD − book_stock_eod) × HPP', (static function () use ($items, $recon, $costBySku): bool {
            $by = [];
            foreach ($recon as $r) { $by[$r['sku']] = $r; }
            foreach ($items as $i) {
                if ($i['variance_qty'] === null) { continue; }
                $r = $by[$i['sku']];
                if (abs($i['system_qty'] - (float) $r['book_stock_eod']) > 0.0000001) { return false; }
            }
            return true;
        })());
    }
    $adj = $sql("SELECT COALESCE(SUM(ROUND(sa.qty_base_delta * sa.unit_cost_base, 2)),0)
                   FROM stock_adjustments sa
                  WHERE sa.adjustment_type = 'OPNAME' AND sa.id IN (
                        SELECT sol.adjustment_id FROM stock_opname_lines sol WHERE sol.session_id = {$sid} AND sol.adjustment_id IS NOT NULL
                        UNION
                        SELECT sa2.id FROM stock_adjustments sa2
                          JOIN inventory_transactions it ON it.id = sa2.transaction_id
                          JOIN stock_opname_sessions sos ON sos.id = {$sid}
                          JOIN stock_opname_lines sol2 ON sol2.session_id = sos.id AND it.transaction_uuid = CONCAT(sos.session_uuid, ':', sol2.item_id)
                         WHERE sa2.adjustment_type = 'OPNAME')");
    $check('Adjustment Bersih = SQL Σ ROUND(qty_base_delta × unit_cost_base, 2) of the linked stock_adjustments', $near($d['kpi']['adjustment_bersih']['value'], $adj), $rp($adj));
    $report[] = $current;
}

$pdo->exec('ROLLBACK');

if ($asJson) {
    echo json_encode(['failures' => $failures, 'sessions' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
} else {
    echo "\n" . ($failures === 0 ? 'ALL CHECKS PASSED' : "{$failures} CHECK(S) FAILED") . " — read-only run, nothing was written.\n";
}
exit($failures === 0 ? 0 : 1);

function StockOpnameBookStockService_reconciliation(PDO $pdo, int $sid): array
{
    return \App\Services\StockOpnameBookStockService::reconciliation($pdo, $sid);
}
