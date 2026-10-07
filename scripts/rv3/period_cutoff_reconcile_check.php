<?php
declare(strict_types=1);

require_once is_file(__DIR__ . '/rv3_bootstrap.php') ? __DIR__ . '/rv3_bootstrap.php' : __DIR__ . '/rv3/rv3_bootstrap.php';
$__rv3_payload = rv3_bootstrap_args($argv);

/**
 * READ-ONLY reconciliation of the PERIOD CUTOFF / OPENING-BALANCE periodization on the real ledger (one READ ONLY transaction, a write is proved to be rejected first).
 *
 *   1. integrity of inventory_effective_dates (when the table exists): every row points at a live ledger transaction, the stored original transaction_date / posting_date equal the live
 *      ledger columns (proof nothing was edited), and effective_at is never LATER than the original date (an effective date can only close an earlier period);
 *   2. dormant when empty: with no override row every period report reads the plain transaction_date (InventoryEffectiveDateService::col);
 *   3. month continuity, for "Semua Gudang" and every warehouse, over the last N months: opening of month m+1 == closing of month m
 *      PLUS the OPENING-type transactions dated exactly at the first instant of month m+1 (a controlled opening balance — e.g. Karang Tengah 2026-10-01 — IS the opening of that
 *      month, never a movement of the previous one), so the SO adjustment of a closed month is in the next month's opening and nowhere in its movements;
 *   4. identity opening + IN − OUT + Transfer IN − Transfer OUT + Adjustment = closing for each of those months.
 *
 *   php period_cutoff_reconcile_check.php --app-root=<dir with services/> [--months=4] [--end=YYYY-MM-DD]
 */

$appRoot = $end = null;
$months = 4;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) { $appRoot = rtrim(substr($arg, 11), '/'); }
    elseif (str_starts_with($arg, '--months=')) { $months = max(1, min(12, (int) substr($arg, 9))); }
    elseif (str_starts_with($arg, '--end=')) { $end = substr($arg, 6); }
    elseif (str_starts_with($arg, '--start=')) { /* accepted for symmetry with the other checks (the months are derived from --end) */ }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); rv3_script_exit(2); }
}
$end ??= date('Y-m-d');
if ($appRoot === null || !is_dir("{$appRoot}/services") || strtotime($end) === false) {
    fwrite(STDERR, "usage: php period_cutoff_reconcile_check.php --app-root=<dir> [--months=4] [--end=YYYY-MM-DD]\n");
    rv3_script_exit(2);
}
rv3_bootstrap_load($appRoot, $__rv3_payload, ['App\\Services\\MovementReportV3Service', 'App\\Services\\InventoryEffectiveDateService']);

use App\Services\Database;
use App\Services\InventoryEffectiveDateService;
use App\Services\MovementReportV3Service as M;

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
$fail = 0;
$n = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail, &$n): void {
    $n++;
    if (!$ok) { $fail++; }
    echo ($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
};
$near = static fn (float $a, float $b, float $e = 0.05): bool => abs($a - $b) <= $e;

InventoryEffectiveDateService::resetCache();
$active = InventoryEffectiveDateService::active($pdo);
$table = true;
try {
    $rows = $pdo->query('SELECT e.transaction_id, e.effective_at, e.original_transaction_date, e.original_posting_date, e.source_type, e.source_id, t.id AS live_id, t.transaction_date, t.posting_date
                           FROM inventory_effective_dates e LEFT JOIN inventory_transactions t ON t.id = e.transaction_id')->fetchAll();
} catch (PDOException $e) {
    $table = false;
    $rows = [];
}
echo $table ? 'inventory_effective_dates: ' . count($rows) . " override row(s)\n" : "inventory_effective_dates: table not installed (every report reads transaction_date — nothing changes)\n";
$check('dormant when there is no override: the reporting date is the plain transaction_date', $active || InventoryEffectiveDateService::col($pdo) === 't.transaction_date');
$check('every override row points at a live ledger transaction', count(array_filter($rows, static fn ($r) => $r['live_id'] === null)) === 0, count($rows) . ' rows');
$check('NOTHING was edited: the stored original transaction_date / posting_date equal the live ledger columns', count(array_filter($rows, static fn ($r) => $r['live_id'] !== null && ($r['original_transaction_date'] !== $r['transaction_date'] || ($r['original_posting_date'] ?? null) !== ($r['posting_date'] ?? null)))) === 0);
$check('an effective date only closes an EARLIER period (effective_at <= the original transaction_date)', count(array_filter($rows, static fn ($r) => $r['live_id'] !== null && $r['effective_at'] > $r['transaction_date'])) === 0);

$warehouses = $pdo->query('SELECT id, code FROM warehouses ORDER BY id')->fetchAll();
$scopes = ['Semua Gudang' => null];
foreach ($warehouses as $w) {
    $scopes[$w['code']] = (int) $w['id'];
}
$td = InventoryEffectiveDateService::col($pdo);
$sv = App\Services\InventoryHppReportService::SIGNED_VALUE_SQL;
for ($i = $months; $i >= 1; $i--) {
    $m0 = date('Y-m-01', strtotime(date('Y-m-01', strtotime($end)) . " -{$i} months"));   // the earlier month of the pair
    $m1 = date('Y-m-01', strtotime($m0 . ' +1 month'));
    $m0End = date('Y-m-t', strtotime($m0));
    $m1End = date('Y-m-t', strtotime($m1));
    foreach ($scopes as $label => $wh) {
        $a = M::overview($pdo, $m0, $m0End, $wh)['split_totals'];
        $b = M::overview($pdo, $m1, $m1End, $wh)['split_totals'];
        $q = $pdo->prepare("SELECT COALESCE(SUM({$sv}),0) FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id
                             WHERE t.transaction_type = 'OPENING' AND t.status IN ('POSTED','VOID') AND t.inventory_effect = 1 AND {$td} = :b" . ($wh !== null ? ' AND l.warehouse_id = :w' : ''));
        $bind = ['b' => $m1 . ' 00:00:00'] + ($wh !== null ? ['w' => $wh] : []);
        $q->execute($bind);
        $boundary = (float) $q->fetchColumn();
        $check("[{$label}] {$m0}..{$m0End} → {$m1}: opening of the next month == closing + controlled opening balance dated {$m1} 00:00:00", $near((float) $b['opening'], (float) $a['closing'] + $boundary), sprintf('closing=%.4f boundary-opening=%.4f next opening=%.4f', $a['closing'], $boundary, $b['opening']));
        $check("[{$label}] identity in {$m0} and {$m1}", $near((float) $a['difference'], 0.0) && $near((float) $b['difference'], 0.0), sprintf('%.4f / %.4f', $a['difference'], $b['difference']));
    }
}
$pdo->exec('ROLLBACK');
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED — DO NOT DEPLOY" : ' — all reconcile') . "\n";
rv3_script_exit($fail ? 1 : 0);
