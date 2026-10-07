<?php
declare(strict_types=1);

/**
 * PERIOD CUTOFF — controlled reporting-date override for Stock Opname adjustments (see pc_lib.php).
 *
 *   php period_cutoff.php preview  --app-root=<app> --sessions=11,12 --cutoff=2026-09-30 [--package-dir=<package>]
 *       READ ONLY (one READ ONLY transaction, rolled back; MySQL itself rejects a write — proved first). Lists the sessions, their adjustment transactions (original dates
 *       untouched), the proposed effective date, and the BEFORE → AFTER figures of the next month per warehouse and company-wide. Prints the PREVIEW SHA256.
 *   php period_cutoff.php post     --app-root=<app> --sessions=… --cutoff=… --preview-sha=<sha256> --actor=<SUPERADMIN> [--yes]
 *       WITHOUT --yes: repeats the preview and exits 10 "NOT APPLIED". With --yes: creates the (additive) table if missing and inserts the rows in ONE transaction.
 *   php period_cutoff.php verify   --app-root=<app> --sessions=… --cutoff=… [--ledger-checksum=<sha256 printed by preview>]   READ ONLY reconciliation.
 *       First decides how many override rows are required: > 0 → inventory_effective_dates must exist with those rows (OVERRIDE_APPLIED); = 0 → the table is NOT required and
 *       absence is valid (NO_OVERRIDE_REQUIRED) — the result is proven straight from the transactions either way.
 *   php period_cutoff.php rollback --app-root=<app> --sessions=… [--yes --confirm=PERIOD_CUTOFF] [--drop-table]
 *       removes ONLY the override rows of those sessions (reports return to the original dates); the ledger was never edited so there is nothing else to undo.
 *
 * Exit codes: 0 ok · 1 verification failed · 2 usage · 3 abort · 10 NOT APPLIED · 11 blocked · 13 preview mismatch · 14 invalid actor · 16 post-verify failed.
 * It never edits inventory_transactions / lines / batches / stock_adjustments / stock_opname_*, and never touches FIFO layers or quantities.
 */

require_once __DIR__ . '/rv3_bootstrap.php';
require_once __DIR__ . '/pc_lib.php';

$payload = rv3_bootstrap_args($argv);
// the mode is the first argument (preview|post|verify|rollback) or --mode=<mode> (when the read-only validator runs the script in-process)
$mode = isset($argv[1]) && !str_starts_with($argv[1], '--') ? $argv[1] : '';
$opt = ['mode' => null, 'app-root' => null, 'sessions' => null, 'cutoff' => null, 'preview-sha' => null, 'actor' => null, 'confirm' => null, 'ledger-checksum' => null];
$yes = false;
$drop = false;
foreach (array_slice($argv, $mode === '' ? 1 : 2) as $a) {
    if ($a === '--yes') {
        $yes = true;
    } elseif ($a === '--drop-table') {
        $drop = true;
    } elseif (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "unknown argument: {$a}\n");
        rv3_script_exit(2);
    }
}
$sessions = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $opt['sessions'])), static fn (int $i) => $i > 0)));
if ($mode === '' && $opt['mode'] !== null) {
    $mode = (string) $opt['mode'];
}
if (!in_array($mode, ['preview', 'post', 'verify', 'rollback'], true) || $opt['app-root'] === null || !is_dir($opt['app-root'] . '/services') || $sessions === [] || ($mode !== 'rollback' && $opt['cutoff'] === null)) {
    fwrite(STDERR, "usage: php period_cutoff.php preview|post|verify|rollback --app-root=<dir with services/> --sessions=11,12 --cutoff=YYYY-MM-DD [--preview-sha=… --actor=… --yes]\n");
    rv3_script_exit(2);
}
$appRoot = rtrim($opt['app-root'], '/');
rv3_bootstrap_load($appRoot, $payload, ['App\\Services\\Database', 'App\\Services\\InventoryEffectiveDateService', 'App\\Services\\InventoryHppReportService', 'App\\Services\\AuditService']);

use App\Services\Database;

$say = static function (string $l): void {
    echo $l . "\n";
};
$pdo = Database::connection();
$readOnly = static function (callable $fn) use ($pdo) {
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION');
    try {
        $pdo->exec('UPDATE items SET id = id WHERE 1 = 0');
        $pdo->exec('ROLLBACK');
        fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction — refusing to continue.\n");
        rv3_script_exit(3);
    } catch (PDOException $e) {
        // expected: the server refused the write
    }
    try {
        return $fn();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        $pdo->exec('SET SESSION TRANSACTION READ WRITE');
    }
};

try {
    if ($mode === 'preview' || ($mode === 'post' && !$yes)) {
        $plan = $readOnly(static fn () => pc_plan($pdo, $sessions, (string) $opt['cutoff']));
        foreach (pc_report_lines($plan) as $l) {
            $say($l);
        }
        if ($mode === 'post') {
            $say('');
            $say('NOT APPLIED — post was started without --yes; nothing was written.');
            rv3_script_exit(10);
        }
        rv3_script_exit($plan['blocked'] ? 11 : 0);
    }
    if ($mode === 'post') {
        if ($opt['preview-sha'] === null || $opt['actor'] === null) {
            fwrite(STDERR, "post needs --preview-sha=<sha256 printed by preview> and --actor=<SUPERADMIN username>\n");
            rv3_script_exit(2);
        }
        $plan = $readOnly(static fn () => pc_plan($pdo, $sessions, (string) $opt['cutoff']));
        $res = pc_post($pdo, $plan, (string) $opt['actor'], (string) $opt['preview-sha']);
        $say('WRITTEN ' . json_encode($res) . "  cutoff {$opt['cutoff']}");
        $say('Now run: period_cutoff.php verify …');
        rv3_script_exit(0);
    }
    if ($mode === 'verify') {
        $v = $readOnly(static fn () => pc_verify_full($pdo, $sessions, (string) $opt['cutoff'], $opt['ledger-checksum']));
        $fail = 0;
        $groupFail = ['override' => 0, 'ledger' => 0, 'report' => 0];
        foreach ($v['checks'] as [$name, $ok, $detail, $group]) {
            $say(($ok ? 'PASS' : 'FAIL') . " - {$name}  [{$detail}]");
            $fail += $ok ? 0 : 1;
            $groupFail[$group] += $ok ? 0 : 1;
        }
        $say('');
        if ($fail === 0) {
            $say($v['status'] === 'NO_OVERRIDE_REQUIRED' ? 'PASS - period cutoff already correct' : 'PASS - period cutoff applied');
            $say('sessions: ' . implode(',', $sessions));
            $say('cutoff: ' . $opt['cutoff']);
            $say('override rows required: ' . $v['required']);
            $say('inventory_effective_dates: ' . ($v['required'] === 0 ? 'NOT REQUIRED' . ($v['table_exists'] ? " (table present, {$v['override_rows']} row(s), not needed)" : ' (table absent — valid)') : "REQUIRED — {$v['override_rows']} row(s) present"));
            $say('ledger unchanged: PASS');
            $say('report periodization: PASS');
            $say('LEDGER CHECKSUM: ' . $v['ledger_checksum']);
            $say('STATUS: ' . $v['status']);
        }
        $say($fail === 0 ? 'VERIFY OK' : "VERIFY FAILED ({$fail})");
        rv3_script_exit($fail === 0 ? 0 : 1);
    }
    // rollback
    if (!pc_table_exists($pdo)) {
        $say('nothing to roll back: inventory_effective_dates does not exist.');
        rv3_script_exit(0);
    }
    $n = (int) $pdo->query("SELECT COUNT(*) FROM inventory_effective_dates WHERE source_type = '" . PC_SOURCE . "' AND source_id IN (" . implode(',', $sessions) . ')')->fetchColumn();
    $say("ROLLBACK PLAN: remove {$n} override row(s) of sessions " . implode(',', $sessions) . '; the ledger rows were never edited, reports return to the original dates.');
    if (!$yes || $opt['confirm'] !== PC_CONFIRM) {
        $say('NOT APPLIED — add  --yes --confirm=' . PC_CONFIRM . '  to execute.');
        rv3_script_exit(10);
    }
    $say('ROLLED BACK ' . json_encode(pc_rollback($pdo, $sessions, $drop)));
    rv3_script_exit(0);
} catch (PcException $e) {
    fwrite(STDERR, "{$e->codeName}: {$e->getMessage()}\n");
    rv3_script_exit($e->exitCode);
}
