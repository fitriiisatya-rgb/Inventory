<?php
declare(strict_types=1);

/**
 * Karang Tengah WAREHOUSE ACTIVATION — the LAST step of the cutover, only after the opening balance is posted AND reconciled.
 *
 *   php kt_activate.php plan     --app-root=<app> --source=<xlsx> [--package-dir=<package>]
 *       READ ONLY. Shows the state of every warehouse (SCM / Cibadak / Karang Tengah), the gates (opening posted, ledger reconciliation, report reconciliation, not already active) and the
 *       exact change (warehouses.is_active 0→1 + activation_locked 1→0 for ONE row, no stock movement). Prints the PLAN SHA256.
 *   php kt_activate.php activate --app-root=<app> --source=<xlsx> --plan-sha=<sha256> --actor=<SUPERADMIN username> [--yes]
 *       Without --yes: exit 10 "NOT APPLIED". With --yes: ONE transaction — UPDATE of that single warehouse row, then proof INSIDE the transaction that no ledger row / FIFO layer / quantity /
 *       value changed (counts + sums before == after), audit row with before/after. Idempotent: an already active + unlocked warehouse is left alone (exit 0, no audit row).
 *   php kt_activate.php rollback --app-root=<app> --source=<xlsx> [--yes --confirm=ACTIVATE_ROLLBACK]
 *       Restores the state recorded in the activation audit row — ONLY while no operational transaction exists in the warehouse other than the opening (no Stock IN / OUT / transfer / opname /
 *       adjustment after activation), and no stock opname session / transfer references it.
 *
 * Never creates a warehouse, a ledger row or a FIFO layer; never touches another warehouse. Exit codes: 0 ok · 2 usage · 3 abort · 10 NOT APPLIED · 11 gate failed · 13 plan changed · 14 invalid actor.
 */

require_once __DIR__ . '/rv3_bootstrap.php';
require_once __DIR__ . '/kt_opening_lib.php';

$payload = rv3_bootstrap_args($argv);
$mode = isset($argv[1]) && !str_starts_with($argv[1], '--') ? $argv[1] : '';
$opt = ['mode' => null, 'app-root' => null, 'source' => null, 'warehouse-code' => KT_WAREHOUSE_CODE, 'plan-sha' => null, 'actor' => null, 'confirm' => null];
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
if (!in_array($mode, ['plan', 'activate', 'rollback'], true) || $opt['app-root'] === null || !is_dir($opt['app-root'] . '/services') || $opt['source'] === null) {
    fwrite(STDERR, "usage: php kt_activate.php plan|activate|rollback --app-root=<dir with services/> --source=<Hasil_SO xlsx> [--package-dir=…] [--plan-sha=… --actor=… --yes]\n");
    rv3_script_exit(2);
}
$appRoot = rtrim($opt['app-root'], '/');
rv3_bootstrap_load($appRoot, $payload, ['App\\Services\\Database', 'App\\Services\\FifoService', 'App\\Services\\XlsxReaderService', 'App\\Services\\AuditService', 'App\\Services\\MovementReportV3Service']);

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

/** the fingerprint of the ledger of the WHOLE company: activation must leave every one of these numbers exactly as they were */
$fingerprint = static function (PDO $db): array {
    return [
        'transactions' => (int) $db->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn(),
        'lines' => (int) $db->query('SELECT COUNT(*) FROM inventory_transaction_lines')->fetchColumn(),
        'batches' => (int) $db->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn(),
        'qty' => (string) $db->query('SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches')->fetchColumn(),
        'value' => (string) $db->query('SELECT COALESCE(SUM(qty_base * unit_cost_base),0) FROM inventory_batches')->fetchColumn(),
        'allocations' => (int) $db->query('SELECT COUNT(*) FROM fifo_allocations')->fetchColumn(),
    ];
};

try {
    $source = kt_read_source($opt['source']);
} catch (Throwable $e) {
    fwrite(STDERR, 'ABORT: ' . $e->getMessage() . "\n");
    rv3_script_exit(3);
}

/** @return array<string,mixed> */
$build = static function () use ($pdo, $source, $opt, $fingerprint): array {
    $plan = kt_plan($pdo, $source, (string) $opt['warehouse-code']);
    $whs = $pdo->query('SELECT id, code, name, warehouse_type, is_active, activation_locked FROM warehouses ORDER BY id')->fetchAll();
    $w = $plan['warehouse'];
    $gates = [];
    $gates[] = ['Karang Tengah exists in warehouses (never created by this process)', $w['warehouse_found'], (string) ($w['warehouse_id'] ?? '—')];
    $planned = count(array_filter($plan['rows'], static fn ($x) => $x['postable']));
    $gates[] = ["opening balance posted: {$planned} OPENING transactions of " . KT_REFERENCE, $w['already_posted'] && (int) $w['already_posted_transactions'] === $planned, "posted={$w['already_posted_transactions']} planned={$planned}"];
    $ledger = $w['already_posted'] ? kt_verify_ledger($pdo, $plan) : [['ledger reconciliation (needs the posted opening)', false, 'opening not posted']];
    $reports = $w['already_posted'] ? kt_verify_reports($pdo, $plan) : [['report reconciliation (needs the posted opening)', false, 'opening not posted']];
    foreach (array_merge($ledger, $reports) as [$n, $ok, $d]) {
        $gates[] = [$n, $ok, $d];
    }
    $state = ($w['is_active'] ?? null) === 1 && ($w['activation_locked'] ?? null) === 0 ? 'ALREADY_ACTIVE' : (($w['is_active'] ?? null) === 0 ? 'READY_TO_ACTIVATE' : 'UNEXPECTED');
    if ($state === 'UNEXPECTED') {
        $gates[] = ['warehouse state is inactive (is_active=0) or already active+unlocked', false, json_encode(['is_active' => $w['is_active'], 'activation_locked' => $w['activation_locked']])];
    }
    $ok = count(array_filter($gates, static fn ($g) => !$g[1])) === 0;
    return ['warehouse_id' => $w['warehouse_id'], 'before' => ['is_active' => $w['is_active'], 'activation_locked' => $w['activation_locked']], 'state' => $state, 'gates' => $gates, 'gates_ok' => $ok, 'warehouses' => $whs,
        'fingerprint' => $fingerprint($pdo), 'plan' => $plan,
        'sha' => hash('sha256', json_encode([$w['warehouse_id'], $w['is_active'], $w['activation_locked'], $plan['preview_sha'], $w['already_posted_transactions']]))];
};

try {
    if ($mode === 'plan' || ($mode === 'activate' && !$yes)) {
        $a = $readOnly($build);
        $say('=== KARANG TENGAH ACTIVATION — PLAN (READ ONLY) ===');
        $say('Gudang saat ini:');
        foreach ($a['warehouses'] as $w) {
            $say(sprintf('  #%-3d %-16s %-28s tipe %-8s is_active=%d activation_locked=%d', $w['id'], $w['code'], $w['name'], $w['warehouse_type'], $w['is_active'], $w['activation_locked']));
        }
        $say('');
        $say('GERBANG (semua harus PASS sebelum aktivasi):');
        foreach ($a['gates'] as [$n, $ok, $d]) {
            $say(($ok ? 'PASS' : 'FAIL') . " - {$n}  [{$d}]");
        }
        $say('');
        $say('PERUBAHAN: UPDATE warehouses SET is_active = 1, activation_locked = 0 WHERE id = ' . ($a['warehouse_id'] ?? '?') . '  (satu baris; tidak ada transaksi, FIFO layer, qty atau nilai yang dibuat / diubah; gudang lain tidak disentuh)');
        $say('SESUDAH: gudang tampil di semua pemilih gudang yang memakai is_active=1 (filter dashboard, filter laporan, Stock IN, Stock OUT, Transfer, Stock Opname) dengan model izin yang sama seperti Cibadak.');
        $say('Sidik jari ledger (harus identik sesudah aktivasi): ' . json_encode($a['fingerprint']));
        $say('PLAN SHA256 : ' . $a['sha']);
        $say('STATUS      : ' . ($a['state'] === 'ALREADY_ACTIVE' ? 'sudah aktif — tidak ada yang perlu dilakukan' : ($a['gates_ok'] ? 'SIAP (belum ada yang ditulis)' : 'DIBLOKIR — gerbang belum lulus')));
        if ($mode === 'activate') {
            $say('NOT APPLIED — activate was started without --yes; nothing was written.');
            rv3_script_exit(10);
        }
        rv3_script_exit($a['state'] === 'ALREADY_ACTIVE' || $a['gates_ok'] ? 0 : 11);
    }
    if ($mode === 'activate') {
        if ($opt['plan-sha'] === null || $opt['actor'] === null) {
            fwrite(STDERR, "activate needs --plan-sha=<sha256 printed by plan> and --actor=<SUPERADMIN username>\n");
            rv3_script_exit(2);
        }
        $a = $readOnly($build);
        if ($a['state'] === 'ALREADY_ACTIVE') {
            $say('ALREADY ACTIVE — nothing to do (idempotent, no audit row written).');
            rv3_script_exit(0);
        }
        if (!$a['gates_ok']) {
            fwrite(STDERR, "GATES_FAILED: the opening balance is not posted + reconciled — Karang Tengah is NOT activated\n");
            rv3_script_exit(11);
        }
        if (!hash_equals($a['sha'], (string) $opt['plan-sha'])) {
            fwrite(STDERR, "PLAN_MISMATCH: current plan sha is {$a['sha']}, you passed {$opt['plan-sha']} — re-run plan and review it again\n");
            rv3_script_exit(13);
        }
        $u = $pdo->prepare('SELECT u.id, u.username, u.is_active, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = :u');
        $u->execute(['u' => (string) $opt['actor']]);
        $actor = $u->fetch();
        if (!$actor || (int) $actor['is_active'] !== 1 || $actor['role_code'] !== 'SUPERADMIN') {
            fwrite(STDERR, "ACTOR_INVALID: --actor must be an ACTIVE SUPERADMIN user\n");
            rv3_script_exit(14);
        }
        $whId = (int) $a['warehouse_id'];
        $res = Database::transaction(function (PDO $tx) use ($whId, $actor, $fingerprint, $a) {
            $before = $fingerprint($tx);
            $n = $tx->prepare('UPDATE warehouses SET is_active = 1, activation_locked = 0 WHERE id = :id AND is_active = 0');
            $n->execute(['id' => $whId]);
            if ($n->rowCount() !== 1) {
                throw new RuntimeException('exactly one warehouse row must change — nothing was changed');
            }
            $after = $fingerprint($tx);
            if ($before !== $after) {
                throw new RuntimeException('activation changed the ledger / FIFO layers — rolled back: ' . json_encode([$before, $after]));
            }
            AuditService::log($tx, (int) $actor['id'], (string) $actor['username'], 'WAREHOUSE_ACTIVATE_CUTOVER', 'warehouses', $whId, ['is_active' => 0, 'activation_locked' => $a['before']['activation_locked']],
                ['is_active' => 1, 'activation_locked' => 0, 'source_reference' => KT_REFERENCE, 'ledger_fingerprint' => $after], KT_REFERENCE);
            return $after;
        });
        $say('ACTIVATED warehouse id ' . $whId . ' — ledger fingerprint unchanged: ' . json_encode($res));
        rv3_script_exit(0);
    }
    // ---- rollback
    $a = $readOnly($build);
    $whId = $a['warehouse_id'];
    if ($whId === null) {
        fwrite(STDERR, "ABORT: warehouse not found\n");
        rv3_script_exit(3);
    }
    $log = $pdo->prepare("SELECT id, created_at, before_data FROM audit_logs WHERE action_code = 'WAREHOUSE_ACTIVATE_CUTOVER' AND entity_type = 'warehouses' AND entity_id = :w ORDER BY id DESC LIMIT 1");
    $log->execute(['w' => $whId]);
    $row = $log->fetch();
    $problems = [];
    if (!$row) {
        $problems[] = 'no activation audit row for this warehouse (nothing this process activated)';
    }
    $ops = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE warehouse_id = :w AND NOT (transaction_type = 'OPENING' AND reference_no = :r)");
    $ops->execute(['w' => $whId, 'r' => KT_REFERENCE]);
    if ((int) $ops->fetchColumn() > 0) {
        $problems[] = 'operational transactions exist in the warehouse after activation (Stock IN / OUT / transfer / adjustment …)';
    }
    $so = $pdo->prepare('SELECT COUNT(*) FROM stock_opname_sessions WHERE warehouse_id = :w');
    $so->execute(['w' => $whId]);
    if ((int) $so->fetchColumn() > 0) {
        $problems[] = 'a Stock Opname session exists for the warehouse';
    }
    $tr = $pdo->prepare('SELECT COUNT(*) FROM warehouse_transfers WHERE from_warehouse_id = :w OR to_warehouse_id = :w2');
    $tr->execute(['w' => $whId, 'w2' => $whId]);
    if ((int) $tr->fetchColumn() > 0) {
        $problems[] = 'a transfer references the warehouse';
    }
    $say('ACTIVATION ROLLBACK PLAN: warehouse id ' . $whId . ' back to is_active=0, activation_locked=' . ($row ? (json_decode((string) $row['before_data'], true)['activation_locked'] ?? 1) : 1));
    if ($problems) {
        foreach ($problems as $p) {
            $say("BLOCKED: {$p}");
        }
        rv3_script_exit(11);
    }
    if (!$yes || $opt['confirm'] !== 'ACTIVATE_ROLLBACK') {
        $say('NOT APPLIED — add  --yes --confirm=ACTIVATE_ROLLBACK  to execute.');
        rv3_script_exit(10);
    }
    $lock = (int) (json_decode((string) $row['before_data'], true)['activation_locked'] ?? 1);
    Database::transaction(static function (PDO $tx) use ($whId, $lock) {
        $tx->prepare('UPDATE warehouses SET is_active = 0, activation_locked = :l WHERE id = :id')->execute(['l' => $lock, 'id' => $whId]);
        AuditService::log($tx, null, 'kt_activate_cli', 'WAREHOUSE_ACTIVATE_ROLLBACK', 'warehouses', $whId, ['is_active' => 1], ['is_active' => 0, 'activation_locked' => $lock], KT_REFERENCE);
    });
    $say('ROLLED BACK — warehouse is inactive again.');
    rv3_script_exit(0);
} catch (Throwable $e) {
    if ($e instanceof RV3ScriptExit) {
        throw $e;
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    rv3_script_exit(3);
}
