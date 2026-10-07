<?php
declare(strict_types=1);

/**
 * WAREHOUSE MODEL — SCM = MAIN · CIBADAK = TRANSIT · KARANG_TENGAH = TRANSIT  (see wm_lib.php).
 *
 *   php warehouse_model.php check    --app-root=<app> [--package-dir=<package>]
 *       READ ONLY (one READ ONLY transaction, a write is proved to be rejected first): every warehouse (type vs intended, active, locked, ledger rows, FIFO value, transfers, SO sessions),
 *       every reader of warehouse_type in the INSTALLED code, the exact one-row change and its effect. Prints the PLAN SHA256.
 *   php warehouse_model.php fix      --app-root=<app> --plan-sha=<sha256> --actor=<SUPERADMIN> [--yes]
 *       Without --yes: exit 10 "NOT APPLIED". With --yes: ONE transaction — UPDATE of warehouse_type for CIBADAK only (MAIN → TRANSIT), with the proof inside the transaction that the ledger /
 *       FIFO / transfers / Stock Opname sessions / Reports V3 totals are identical before and after (otherwise rolled back). Refused when the installed code reads the type anywhere unknown.
 *   php warehouse_model.php verify   --app-root=<app>                      READ ONLY
 *   php warehouse_model.php rollback --app-root=<app> [--yes --confirm=WAREHOUSE_TYPE_ROLLBACK]   restores the type recorded in the audit row.
 *
 * Exit codes: 0 ok · 1 verify failed · 2 usage · 3 abort · 10 NOT APPLIED · 11 blocked · 13 plan changed · 14 invalid actor · 16 proof failed (rolled back).
 */

require_once __DIR__ . '/rv3_bootstrap.php';
require_once __DIR__ . '/wm_lib.php';

$payload = rv3_bootstrap_args($argv);
$mode = isset($argv[1]) && !str_starts_with($argv[1], '--') ? $argv[1] : '';
$opt = ['mode' => null, 'app-root' => null, 'plan-sha' => null, 'actor' => null, 'confirm' => null, 'warehouse-code' => 'CIBADAK'];
$yes = false;
foreach (array_slice($argv, $mode === '' ? 1 : 2) as $a) {
    if ($a === '--yes') {
        $yes = true;
    } elseif (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "unknown argument: {$a}\n");
        rv3_script_exit(2);
    }
}
if ($mode === '' && $opt['mode'] !== null) {
    $mode = (string) $opt['mode'];
}
if (!in_array($mode, ['check', 'fix', 'verify', 'rollback'], true) || $opt['app-root'] === null || !is_dir($opt['app-root'] . '/services')) {
    fwrite(STDERR, "usage: php warehouse_model.php check|fix|verify|rollback --app-root=<dir with services/> [--package-dir=…] [--plan-sha=… --actor=… --yes]\n");
    rv3_script_exit(2);
}
$appRoot = rtrim($opt['app-root'], '/');
rv3_bootstrap_load($appRoot, $payload, ['App\\Services\\Database', 'App\\Services\\AuditService']);

use App\Services\AuditService;
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
        // expected
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
    if ($mode === 'check' || ($mode === 'fix' && !$yes)) {
        $plan = $readOnly(static fn () => wm_plan($pdo, $appRoot, (string) $opt['warehouse-code']));
        foreach (wm_lines($plan) as $l) {
            $say($l);
        }
        if ($mode === 'fix') {
            $say('NOT APPLIED — fix was started without --yes; nothing was written.');
            rv3_script_exit(10);
        }
        rv3_script_exit($plan['blocked'] ? 11 : 0);
    }
    if ($mode === 'fix') {
        if ($opt['plan-sha'] === null || $opt['actor'] === null) {
            fwrite(STDERR, "fix needs --plan-sha=<sha256 printed by check> and --actor=<SUPERADMIN username>\n");
            rv3_script_exit(2);
        }
        $plan = $readOnly(static fn () => wm_plan($pdo, $appRoot, (string) $opt['warehouse-code']));
        $res = wm_apply($pdo, $plan, (string) $opt['actor'], (string) $opt['plan-sha']);
        $say('DONE ' . json_encode($res['changed'] === 1 ? ['changed' => 1, 'proof' => 'ledger / FIFO / transfers / opname / report totals identical before and after'] : $res));
        rv3_script_exit(0);
    }
    if ($mode === 'verify') {
        $fail = 0;
        foreach ($readOnly(static fn () => wm_verify($pdo)) as [$n, $ok, $d]) {
            $say(($ok ? 'PASS' : 'FAIL') . " - {$n}  [{$d}]");
            $fail += $ok ? 0 : 1;
        }
        $say($fail === 0 ? 'VERIFY OK' : "VERIFY FAILED ({$fail})");
        rv3_script_exit($fail === 0 ? 0 : 1);
    }
    // rollback
    $row = $readOnly(static function () use ($pdo, $opt) {
        $q = $pdo->prepare("SELECT a.id, a.entity_id, a.before_data FROM audit_logs a WHERE a.action_code = 'WAREHOUSE_TYPE_CORRECT' AND a.entity_type = 'warehouses' ORDER BY a.id DESC LIMIT 1");
        $q->execute();
        return $q->fetch();
    });
    if (!$row) {
        $say('nothing to roll back: no WAREHOUSE_TYPE_CORRECT audit row.');
        rv3_script_exit(0);
    }
    $prev = (string) (json_decode((string) $row['before_data'], true)['warehouse_type'] ?? '');
    $say("ROLLBACK PLAN: warehouse id {$row['entity_id']} warehouse_type back to {$prev} (descriptive column only)");
    if (!$yes || $opt['confirm'] !== WM_CONFIRM) {
        $say('NOT APPLIED — add  --yes --confirm=' . WM_CONFIRM . '  to execute.');
        rv3_script_exit(10);
    }
    if (!in_array($prev, ['MAIN', 'TRANSIT'], true)) {
        fwrite(STDERR, "ABORT: the audit row holds no valid previous type\n");
        rv3_script_exit(3);
    }
    Database::transaction(static function (PDO $tx) use ($row, $prev) {
        $tx->prepare('UPDATE warehouses SET warehouse_type = :t WHERE id = :id')->execute(['t' => $prev, 'id' => (int) $row['entity_id']]);
        AuditService::log($tx, null, 'warehouse_model_cli', 'WAREHOUSE_TYPE_ROLLBACK', 'warehouses', (int) $row['entity_id'], null, ['warehouse_type' => $prev], 'warehouse model rollback');
    });
    $say('ROLLED BACK.');
    rv3_script_exit(0);
} catch (WmException $e) {
    fwrite(STDERR, "{$e->codeName}: {$e->getMessage()}\n");
    rv3_script_exit($e->exitCode);
}
