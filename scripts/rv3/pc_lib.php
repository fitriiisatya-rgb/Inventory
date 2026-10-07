<?php
declare(strict_types=1);

/**
 * PERIOD CUTOFF — library for scripts/rv3/period_cutoff.php.
 *
 * Stock Opname sessions whose INVENTORY CUTOFF is a past day (e.g. 30 Sep 2026) but which were counted / posted later must close THAT period: the SO adjustment belongs to
 * September's closing and therefore to October's opening — it must not appear again as an October adjustment. Posting timestamps, the audit trail, sessions, adjustments and
 * the ledger rows are NEVER edited: one row per SO adjustment transaction is written to inventory_effective_dates (effective_at = cutoff day 23:59:59, plus the untouched
 * original dates as proof) and every period report reads COALESCE(effective_at, transaction_date) (InventoryEffectiveDateService).
 *
 *   plan    read-only: sessions → their adjustment transactions → current date / proposed effective date / value → impact per warehouse (before / after figures of the month after the cutoff)
 *   post    the only writer: CREATE TABLE IF NOT EXISTS + ONE transaction of INSERTs; bound to the reviewed preview sha; refused on any blocker
 *   verify  read-only
 *
 * Two valid outcomes (decided from the ledger itself, never from the existence of the table):
 *   OVERRIDE_APPLIED     one or more transactions carry a transaction_date that is NOT the cutoff instant → inventory_effective_dates is required and holds exactly those rows
 *   NO_OVERRIDE_REQUIRED every target transaction already carries transaction_date = the cutoff instant → COALESCE(effective_at, transaction_date) resolves to the cutoff with no override
 *                        row at all; the table is NOT required, is not created for cosmetic reasons, and the verifier proves the result straight from the transactions
 */

use App\Services\AuditService;
use App\Services\Database;
use App\Services\InventoryEffectiveDateService;
use App\Services\InventoryHppReportService;

const PC_SOURCE = 'STOCK_OPNAME_SESSION';
const PC_CONFIRM = 'PERIOD_CUTOFF';

final class PcException extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message, public readonly int $exitCode = 11)
    {
        parent::__construct($message);
    }
}

function pc_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM ' . InventoryEffectiveDateService::TABLE . ' LIMIT 1');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/**
 * The sessions and their adjustment transactions (read only). Also used by the verifier, which must not depend on the override table to know WHAT it is verifying.
 * @return array{blockers:list<string>,sessions:list<array<string,mixed>>,txs:array<int,array<string,mixed>>}
 */
function pc_targets(PDO $pdo, array $sessionIds): array
{
    $blockers = [];
    $sessions = [];
    $txs = [];
    foreach ($sessionIds as $sid) {
        $st = $pdo->prepare('SELECT s.id, s.session_number, s.session_date, s.status, s.warehouse_id, s.session_uuid, w.name AS warehouse FROM stock_opname_sessions s JOIN warehouses w ON w.id = s.warehouse_id WHERE s.id = :i');
        $st->execute(['i' => $sid]);
        $s = $st->fetch();
        if (!$s) {
            $blockers[] = "sesi Stock Opname {$sid} tidak ditemukan";
            continue;
        }
        if ($s['status'] !== 'POSTED') {
            $blockers[] = "sesi {$s['session_number']} berstatus {$s['status']} (hanya sesi POSTED yang punya adjustment diposting)";
        }
        // the SAME linkage the Stock Opname audit report uses: the opname line's own adjustment, or the ledger row keyed <session_uuid>:<item_id>
        $q = $pdo->prepare(
            "SELECT DISTINCT t.id
               FROM stock_opname_lines sol
               JOIN stock_opname_sessions sos ON sos.id = sol.session_id
               LEFT JOIN inventory_transactions it ON it.transaction_uuid = CONCAT(sos.session_uuid, ':', sol.item_id)
               LEFT JOIN stock_adjustments sa ON sa.adjustment_type = 'OPNAME' AND (sa.id = sol.adjustment_id OR (it.id IS NOT NULL AND sa.transaction_id = it.id))
               JOIN inventory_transactions t ON t.id = COALESCE(sa.transaction_id, it.id)
              WHERE sol.session_id = :sid AND t.inventory_effect = 1 AND t.warehouse_id = sos.warehouse_id
              ORDER BY t.id"
        );
        $q->execute(['sid' => $sid]);
        $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $sessions[] = ['id' => (int) $s['id'], 'number' => $s['session_number'], 'session_date' => $s['session_date'], 'status' => $s['status'], 'warehouse_id' => (int) $s['warehouse_id'], 'warehouse' => $s['warehouse'], 'transactions' => count($ids)];
        if ($ids === [] && $s['status'] === 'POSTED') {
            $blockers[] = "sesi {$s['session_number']} POSTED tetapi tidak punya transaksi adjustment di ledger";
        }
        foreach ($ids as $tid) {
            $txs[$tid] = ['session' => $s, 'id' => $tid];
        }
    }
    return ['blockers' => $blockers, 'sessions' => $sessions, 'txs' => $txs];
}

/** @return array<string,mixed> */
function pc_plan(PDO $pdo, array $sessionIds, string $cutoff): array
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff) !== 1 || strtotime($cutoff) === false) {
        throw new PcException('USAGE', "--cutoff must be YYYY-MM-DD (got '{$cutoff}')", 2);
    }
    $effective = InventoryEffectiveDateService::cutoffInstant($cutoff);
    $tableExists = pc_table_exists($pdo);
    ['blockers' => $blockers, 'sessions' => $sessions, 'txs' => $txs] = pc_targets($pdo, $sessionIds);
    $rows = [];
    $existing = [];
    if ($tableExists) {
        foreach ($pdo->query('SELECT * FROM ' . InventoryEffectiveDateService::TABLE)->fetchAll() as $e) {
            $existing[(int) $e['transaction_id']] = $e;
        }
    }
    $locked = $pdo->prepare("SELECT COUNT(*) FROM book_closings WHERE status = 'LOCKED' AND :d BETWEEN period_start AND period_end");
    $locked->execute(['d' => $cutoff]);
    if ((int) $locked->fetchColumn() > 0) {
        $blockers[] = "periode {$cutoff} sudah dikunci (book_closings LOCKED) — memindahkan transaksi ke periode terkunci mengubah angka yang sudah ditutup";
    }
    $sv = InventoryHppReportService::SIGNED_VALUE_SQL;
    foreach ($txs as $tid => $x) {
        $r = $pdo->prepare("SELECT t.id, t.transaction_type, t.status, t.warehouse_id, t.transaction_date, t.posting_date, t.created_at, t.reference_no, COALESCE(SUM({$sv}),0) AS value, COUNT(l.id) AS line_count
                              FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id = t.id WHERE t.id = :i GROUP BY t.id, t.transaction_type, t.status, t.warehouse_id, t.transaction_date, t.posting_date, t.created_at, t.reference_no");
        $r->execute(['i' => $tid]);
        $t = $r->fetch();
        if (!$t) {
            continue;
        }
        $state = 'SET';
        $note = '';
        if (isset($existing[$tid])) {
            if ($existing[$tid]['effective_at'] === $effective) {
                $state = 'ALREADY_SET';
            } else {
                $state = 'CONFLICT';
                $blockers[] = "transaksi {$tid} sudah punya effective_at {$existing[$tid]['effective_at']} (bukan {$effective}) — hapus dulu dengan rollback atau samakan";
            }
        } elseif (substr((string) $t['transaction_date'], 0, 10) < $cutoff) {
            $state = 'BLOCKED';
            $blockers[] = "transaksi {$tid} bertanggal {$t['transaction_date']} — sudah sebelum cutoff {$cutoff}; effective date tidak boleh dimajukan";
        } elseif ($t['transaction_date'] === $effective) {
            $state = 'UNCHANGED';
            $note = 'sudah bertanggal cutoff';
        }
        $rows[] = ['transaction_id' => $tid, 'session_id' => (int) $x['session']['id'], 'session_number' => $x['session']['session_number'], 'warehouse_id' => (int) $t['warehouse_id'], 'type' => $t['transaction_type'], 'status' => $t['status'],
            'transaction_date' => $t['transaction_date'], 'posting_date' => $t['posting_date'], 'created_at' => $t['created_at'], 'effective_at' => $effective, 'value' => round((float) $t['value'], 4), 'lines' => (int) $t['line_count'], 'state' => $state, 'note' => $note];
    }
    // ---- impact: the month AFTER the cutoff, per warehouse + company (before = the ledger as reported now; after = exactly the shifted amount moved from movement to opening)
    $from = date('Y-m-d', strtotime($cutoff . ' +1 day'));
    $to = date('Y-m-t', strtotime($from));
    $impact = [];
    $shift = [];
    foreach ($rows as $r) {
        if (!in_array($r['state'], ['SET'], true) || $r['status'] === 'REVERSED') {
            continue;
        }
        $d = substr((string) $r['transaction_date'], 0, 10);
        if ($d >= $from && $d <= $to) {
            $shift[$r['warehouse_id']] = ($shift[$r['warehouse_id']] ?? 0.0) + $r['value'];
        }
    }
    if (class_exists('App\\Services\\MovementReportV3Service')) {
        $scopes = array_unique(array_merge(array_keys($shift), array_map(static fn ($s) => $s['warehouse_id'], $sessions)));
        sort($scopes);
        foreach (array_merge([null], $scopes) as $wh) {
            $o = \App\Services\MovementReportV3Service::overview($pdo, $from, $to, $wh)['split_totals'];
            $x = $wh === null ? array_sum($shift) : ($shift[$wh] ?? 0.0);
            $impact[] = [
                'scope' => $wh === null ? 'Semua Gudang' : (string) ($pdo->query('SELECT name FROM warehouses WHERE id = ' . (int) $wh)->fetchColumn()), 'warehouse_id' => $wh, 'period' => "{$from} .. {$to}",
                'before' => ['opening' => round((float) $o['opening'], 4), 'adjustment' => round((float) $o['adjustment'], 4), 'closing' => round((float) $o['closing'], 4)],
                'shift_value' => round($x, 4),
                'after_expected' => ['opening' => round((float) $o['opening'] + $x, 4), 'adjustment' => round((float) $o['adjustment'] - $x, 4), 'closing' => round((float) $o['closing'], 4)],
            ];
        }
    }
    $toWrite = count(array_filter($rows, static fn ($r) => $r['state'] === 'SET'));
    // an override is REQUIRED for every target whose own transaction_date is not the cutoff instant (it would otherwise report on another day); 0 → nothing to override
    $required = count(array_filter($rows, static fn ($r) => $r['transaction_date'] !== $effective));
    $status = $blockers !== [] ? 'BLOCKED' : ($toWrite > 0 ? 'READY' : ($required === 0 ? 'NO_OVERRIDE_REQUIRED' : 'ALREADY_APPLIED'));
    $plan = ['cutoff' => $cutoff, 'effective_at' => $effective, 'table_exists' => $tableExists, 'sessions' => $sessions, 'transactions' => $rows, 'impact' => $impact, 'blockers' => $blockers, 'blocked' => $blockers !== [],
        'to_write' => $toWrite, 'required_override_count' => $required, 'status' => $status, 'ledger_checksum' => pc_ledger_checksum($pdo, $sessionIds, array_keys($txs))];
    $plan['preview_sha'] = hash('sha256', json_encode([$cutoff, array_map(static fn ($r) => [$r['transaction_id'], $r['transaction_date'], $r['effective_at'], $r['state']], $rows)]));
    return $plan;
}

/** @return list<string> */
function pc_report_lines(array $plan): array
{
    $o = ['=== PERIOD CUTOFF — PREVIEW (READ ONLY) ===', "Cutoff inventori : {$plan['cutoff']}  → effective_at {$plan['effective_at']} (penutup hari itu)", 'Tabel inventory_effective_dates: ' . ($plan['status'] === 'NO_OVERRIDE_REQUIRED' ? 'TIDAK DIPERLUKAN (' . ($plan['table_exists'] ? 'ada, tidak dipakai' : 'tidak ada, tidak akan dibuat') . ')' : ($plan['table_exists'] ? 'sudah ada' : 'belum ada (akan dibuat oleh post)')), "Override yang dibutuhkan: {$plan['required_override_count']} (transaksi yang transaction_date-nya bukan {$plan['effective_at']})", ''];
    $o[] = 'SESI';
    foreach ($plan['sessions'] as $s) {
        $o[] = sprintf('  #%d %s  %s  tanggal sesi %s  %s  → %d transaksi adjustment', $s['id'], $s['number'], $s['status'], $s['session_date'], $s['warehouse'], $s['transactions']);
    }
    $o[] = '';
    $o[] = 'TRANSAKSI (tanggal asli TIDAK diubah; hanya tanggal laporan)';
    $o[] = sprintf('  %-8s %-12s %-10s %-19s %-19s %-19s %16s  %s', 'tx', 'sesi', 'tipe', 'transaction_date', 'posting_date', 'effective_at', 'nilai', 'status');
    foreach ($plan['transactions'] as $r) {
        $o[] = sprintf('  %-8d %-12s %-10s %-19s %-19s %-19s %16s  %s', $r['transaction_id'], $r['session_number'], $r['type'], $r['transaction_date'], $r['posting_date'], $r['effective_at'], number_format($r['value'], 4, ',', '.'), $r['state'] . ($r['note'] ? " ({$r['note']})" : ''));
    }
    $o[] = '';
    $o[] = 'DAMPAK PADA LAPORAN (bulan setelah cutoff) — SEBELUM → SESUDAH (diharapkan)';
    foreach ($plan['impact'] as $i) {
        $o[] = sprintf('  %-22s %s', $i['scope'], $i['period']);
        $o[] = sprintf('      Stok Awal   %18s → %18s', number_format($i['before']['opening'], 4, ',', '.'), number_format($i['after_expected']['opening'], 4, ',', '.'));
        $o[] = sprintf('      Adjustment  %18s → %18s   (nilai yang dipindah ke periode cutoff: %s)', number_format($i['before']['adjustment'], 4, ',', '.'), number_format($i['after_expected']['adjustment'], 4, ',', '.'), number_format($i['shift_value'], 4, ',', '.'));
        $o[] = sprintf('      Stok Akhir  %18s → %18s   (tidak berubah)', number_format($i['before']['closing'], 4, ',', '.'), number_format($i['after_expected']['closing'], 4, ',', '.'));
    }
    $o[] = '';
    $o[] = 'PREVIEW SHA256 : ' . $plan['preview_sha'];
    $o[] = 'LEDGER CHECKSUM: ' . $plan['ledger_checksum'] . '  (sesi + transaksi adjustment + baris + stock_adjustments; simpan untuk `verify --ledger-checksum=…`)';
    $o[] = 'STATUS POSTING : ' . match ($plan['status']) {
        'BLOCKED' => 'DIBLOKIR — ' . count($plan['blockers']) . ' blocker',
        'NO_OVERRIDE_REQUIRED' => 'NO_OVERRIDE_REQUIRED — semua transaksi sudah bertanggal ' . $plan['effective_at'] . '; 0 baris override; inventory_effective_dates tidak diperlukan, tidak ada yang akan ditulis',
        'ALREADY_APPLIED' => "ALREADY_APPLIED — override sudah terpasang untuk {$plan['required_override_count']} transaksi; 0 baris baru",
        default => "SIAP — {$plan['to_write']} baris akan ditulis ke inventory_effective_dates (belum ada yang ditulis)",
    };
    foreach ($plan['blockers'] as $b) {
        $o[] = "  BLOCKER  {$b}";
    }
    return $o;
}

/** @return array<string,mixed> */
function pc_post(PDO $pdo, array $plan, string $actorUsername, string $previewSha): array
{
    if ($plan['blocked']) {
        throw new PcException('BLOCKED', 'refused — ' . count($plan['blockers']) . ' blocker(s)', 11);
    }
    if (!hash_equals($plan['preview_sha'], $previewSha)) {
        throw new PcException('PREVIEW_MISMATCH', "preview sha256 mismatch: current plan is {$plan['preview_sha']}, you passed {$previewSha} — re-run preview and review it again", 13);
    }
    $u = $pdo->prepare('SELECT u.id, u.username, u.is_active, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = :u');
    $u->execute(['u' => $actorUsername]);
    $actor = $u->fetch();
    if (!$actor || (int) $actor['is_active'] !== 1 || $actor['role_code'] !== 'SUPERADMIN') {
        throw new PcException('ACTOR_INVALID', "--actor must be an ACTIVE SUPERADMIN user (got '{$actorUsername}')", 14);
    }
    if ($plan['to_write'] === 0) {
        return ['written' => 0, 'status' => $plan['status'], 'note' => $plan['status'] === 'NO_OVERRIDE_REQUIRED'
            ? 'nothing to write — every transaction already carries this effective date (NO_OVERRIDE_REQUIRED: inventory_effective_dates is not needed and was not created)'
            : 'nothing to write — every override row already exists (ALREADY_APPLIED)'];
    }
    if (!pc_table_exists($pdo)) {
        $pdo->exec(pc_ddl());   // additive, idempotent; the same DDL as database/migrations/2026_10_07_inventory_effective_dates.sql
    }
    $res = Database::transaction(function (PDO $tx) use ($plan, $actor) {
        $ins = $tx->prepare('INSERT INTO inventory_effective_dates (transaction_id, effective_at, original_transaction_date, original_posting_date, source_type, source_id, source_ref, reason, created_by)
                             VALUES (:t, :e, :o, :p, :st, :si, :sr, :r, :u)');
        $n = 0;
        foreach ($plan['transactions'] as $r) {
            if ($r['state'] !== 'SET') {
                continue;
            }
            $ins->execute(['t' => $r['transaction_id'], 'e' => $r['effective_at'], 'o' => $r['transaction_date'], 'p' => $r['posting_date'], 'st' => PC_SOURCE, 'si' => $r['session_id'], 'sr' => $r['session_number'],
                'r' => "inventory cutoff {$plan['cutoff']} (Stock Opname)", 'u' => (int) $actor['id']]);
            $n++;
        }
        // inside the transaction: the ledger rows were not touched — the stored originals equal the live columns
        $bad = $tx->query("SELECT COUNT(*) FROM inventory_effective_dates e JOIN inventory_transactions t ON t.id = e.transaction_id WHERE e.original_transaction_date <> t.transaction_date OR NOT (e.original_posting_date <=> t.posting_date)")->fetchColumn();
        if ((int) $bad > 0) {
            throw new PcException('POST_VERIFY_FAILED', 'a ledger date differs from the stored original — rolled back', 16);
        }
        AuditService::log($tx, (int) $actor['id'], (string) $actor['username'], 'PERIOD_CUTOFF_SET', 'inventory_effective_dates', null, ['impact_before' => array_map(static fn ($i) => ['scope' => $i['scope'], 'before' => $i['before']], $plan['impact'])], [
            'cutoff' => $plan['cutoff'], 'effective_at' => $plan['effective_at'], 'sessions' => array_column($plan['sessions'], 'id'), 'transactions' => $n, 'preview_sha256' => $plan['preview_sha'],
            'impact_after_expected' => array_map(static fn ($i) => ['scope' => $i['scope'], 'after' => $i['after_expected']], $plan['impact']),
        ], 'period cutoff ' . $plan['cutoff']);
        return ['written' => $n];
    });
    InventoryEffectiveDateService::resetCache();
    return $res;
}

function pc_ddl(): string
{
    return 'CREATE TABLE IF NOT EXISTS inventory_effective_dates (
        transaction_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, effective_at DATETIME NOT NULL, original_transaction_date DATETIME NOT NULL, original_posting_date DATETIME NULL,
        source_type VARCHAR(40) NOT NULL, source_id BIGINT UNSIGNED NOT NULL, source_ref VARCHAR(100) NULL, reason VARCHAR(255) NOT NULL, created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_ied_source (source_type, source_id), INDEX idx_ied_effective (effective_at)
    ) ENGINE=InnoDB';
}

/**
 * Deterministic fingerprint of everything this feature must never touch: the sessions, their adjustment transactions (dates, status, quantities, values), their lines and
 * stock_adjustments. Printed by preview; `verify --ledger-checksum=<sha>` proves it is identical afterwards.
 * @param list<int> $txIds
 */
function pc_ledger_checksum(PDO $pdo, array $sessionIds, array $txIds): string
{
    $sids = implode(',', array_map('intval', $sessionIds)) ?: '0';
    $ids = implode(',', array_map('intval', $txIds)) ?: '0';
    $sv = InventoryHppReportService::SIGNED_VALUE_SQL;
    $parts = [
        $pdo->query("SELECT * FROM stock_opname_sessions WHERE id IN ({$sids}) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query("SELECT t.id, t.transaction_uuid, t.transaction_type, t.status, t.warehouse_id, t.transaction_date, t.posting_date, t.created_at, t.reference_no, COUNT(l.id) AS line_count,
                            ROUND(COALESCE(SUM(l.base_qty),0),6) AS qty, ROUND(COALESCE(SUM(l.subtotal),0),4) AS subtotal, ROUND(COALESCE(SUM({$sv}),0),4) AS value
                       FROM inventory_transactions t LEFT JOIN inventory_transaction_lines l ON l.transaction_id = t.id WHERE t.id IN ({$ids}) GROUP BY t.id ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query("SELECT * FROM stock_adjustments WHERE transaction_id IN ({$ids}) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    ];
    return hash('sha256', json_encode($parts));
}

/**
 * Verification that decides FIRST how many override rows are required (transactions whose own transaction_date is not the cutoff instant):
 *   > 0  → inventory_effective_dates must exist and carry exactly those rows (OVERRIDE_APPLIED)
 *   = 0  → the table is NOT required (NO_OVERRIDE_REQUIRED); everything is proven straight from the transactions
 * and in both cases proves the periodization from the ledger / reports. Each check is [name, ok, detail, group] with group override | ledger | report.
 * @return array{checks:list<array{0:string,1:bool,2:string,3:string}>,status:string,required:int,table_exists:bool,override_rows:int,ledger_checksum:string}
 */
function pc_verify_full(PDO $pdo, array $sessionIds, string $cutoff, ?string $expectChecksum = null): array
{
    InventoryEffectiveDateService::resetCache();
    $effective = InventoryEffectiveDateService::cutoffInstant($cutoff);
    $out = [];
    $t = pc_targets($pdo, $sessionIds);
    $txIds = array_map('intval', array_keys($t['txs']));
    $checksum = pc_ledger_checksum($pdo, $sessionIds, $txIds);
    $out[] = ['target sessions exist, are POSTED and own adjustment transactions', $t['blockers'] === [] && $txIds !== [], $t['blockers'] === [] ? count($txIds) . ' transactions' : implode('; ', $t['blockers']), 'ledger'];
    if ($txIds === []) {
        return ['checks' => $out, 'status' => 'FAILED', 'required' => 0, 'table_exists' => pc_table_exists($pdo), 'override_rows' => 0, 'ledger_checksum' => $checksum];
    }
    $in = implode(',', $txIds);
    $tableExists = pc_table_exists($pdo);
    $live = [];
    foreach ($pdo->query("SELECT id, transaction_date, posting_date, warehouse_id, status FROM inventory_transactions WHERE id IN ({$in}) ORDER BY id")->fetchAll() as $r) {
        $live[(int) $r['id']] = $r;
    }
    $ov = [];
    if ($tableExists) {
        foreach ($pdo->query("SELECT * FROM inventory_effective_dates WHERE transaction_id IN ({$in})")->fetchAll() as $e) {
            $ov[(int) $e['transaction_id']] = $e;
        }
    }
    // ---- 1. how many overrides are REQUIRED? (decided from the transactions, not from the table)
    $needs = array_keys(array_filter($live, static fn ($r) => $r['transaction_date'] !== $effective));
    $required = count($needs);
    $out[] = ["required override rows = {$required} (target transactions whose own transaction_date is not {$effective}; {$required} of " . count($live) . ')', true, 'status ' . ($required > 0 ? 'OVERRIDE_APPLIED expected' : 'NO_OVERRIDE_REQUIRED'), 'override'];
    if ($required > 0) {
        $out[] = ["inventory_effective_dates exists ({$required} override row(s) are required)", $tableExists, $tableExists ? 'present' : 'table missing', 'override'];
        $okRows = count(array_filter($needs, static fn ($id) => isset($ov[$id]) && $ov[$id]['effective_at'] === $effective));
        $out[] = ["every transaction that needs one has an override row with effective_at = {$effective}", $okRows === $required, "{$okRows} / {$required} rows", 'override'];
    } else {
        $out[] = ['inventory_effective_dates: NOT REQUIRED (zero overrides needed; its absence is valid)', true, !$tableExists ? 'table absent' : 'table present with ' . count($ov) . ' row(s) for these transactions — harmless, not required', 'override'];
    }
    // ---- 2. ledger untouched
    $bad = count(array_filter($ov, static fn ($e) => !isset($live[(int) $e['transaction_id']]) || $e['original_transaction_date'] !== $live[(int) $e['transaction_id']]['transaction_date'] || ($e['original_posting_date'] ?? null) !== ($live[(int) $e['transaction_id']]['posting_date'] ?? null)));
    $out[] = ['NOTHING was edited: stored original transaction_date / posting_date == the live ledger columns (' . count($ov) . ' override row(s) compared)', $bad === 0, $bad === 0 ? 'originals intact' : "{$bad} differ", 'ledger'];
    $out[] = ['ledger checksum (sessions + adjustment transactions + lines + stock_adjustments)' . ($expectChecksum !== null ? ' equals the expected one' : ' (pass --ledger-checksum=<value printed by preview> to compare)'), $expectChecksum === null || hash_equals($expectChecksum, $checksum), $checksum, 'ledger'];
    // ---- 3. every target resolves to the cutoff — computed from the rows, then confirmed with the SAME SQL the reports use
    $resolved = array_map(static fn ($id) => isset($ov[$id]) ? $ov[$id]['effective_at'] : $live[$id]['transaction_date'], array_keys($live));
    $okRes = count(array_filter($resolved, static fn ($d) => $d === $effective));
    $out[] = ["every target adjustment resolves to the approved cutoff {$effective} (COALESCE(effective_at, transaction_date))", $okRes === count($live), "{$okRes} / " . count($live)];
    $from = date('Y-m-d', strtotime($cutoff . ' +1 day'));
    $to = date('Y-m-t', strtotime($from));
    $monthStart = date('Y-m-01', strtotime($cutoff));
    $td = InventoryEffectiveDateService::col($pdo);
    $sv = InventoryHppReportService::SIGNED_VALUE_SQL;
    $val = static function (string $a, string $b) use ($pdo, $td, $sv, $in): array {
        $q = $pdo->query("SELECT COUNT(DISTINCT t.id) AS n, COALESCE(SUM({$sv}),0) AS v FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id = t.id
                           WHERE t.id IN ({$in}) AND t.status <> 'REVERSED' AND {$td} >= '{$a} 00:00:00' AND {$td} <= '{$b} 23:59:59'");
        $r = $q->fetch();
        return [(int) $r['n'], (float) $r['v']];
    };
    [$nAll, $vAll] = $val('2000-01-01', '2999-12-31');
    [$nCut, $vCut] = $val($monthStart, $cutoff);
    [$nNext, $vNext] = $val($from, $to);
    $out[] = ["September (cutoff month {$monthStart}..{$cutoff}) closing includes ALL target adjustments (value " . number_format($vAll, 4, '.', '') . ')', $nCut === $nAll && abs($vCut - $vAll) < 0.0001, "in cutoff month: {$nCut} tx / " . number_format($vCut, 4, '.', '') . " of {$nAll} tx / " . number_format($vAll, 4, '.', '')];
    $out[] = ["none of them is counted again as a {$from}..{$to} (October) adjustment or movement", $nNext === 0 && abs($vNext) < 0.0001, "in next month: {$nNext} tx / " . number_format($vNext, 4, '.', '')];
    foreach ($live as $id => $r) {
        $d = isset($ov[$id]) ? $ov[$id]['effective_at'] : $r['transaction_date'];
        if (substr((string) $d, 0, 10) !== $cutoff) {
            $out[] = ["transaction {$id} reports on {$cutoff}", false, "reporting date {$d}"];
        }
    }
    // ---- 4. report continuity (Semua Gudang + every affected warehouse)
    if (class_exists('App\\Services\\MovementReportV3Service')) {
        $wh = array_values(array_unique(array_map(static fn ($r) => (int) $r['warehouse_id'], $live)));
        foreach (array_merge([null], $wh) as $w) {
            $prev = \App\Services\MovementReportV3Service::overview($pdo, $monthStart, $cutoff, $w)['split_totals'];
            $next = \App\Services\MovementReportV3Service::overview($pdo, $from, $to, $w)['split_totals'];
            $label = $w === null ? 'Semua Gudang' : 'gudang ' . $w;
            $out[] = ["{$label}: closing of the cutoff month == opening of the next month (the SO adjustment is already in the opening)", abs((float) $prev['closing'] - (float) $next['opening']) < 0.01, sprintf('closing=%.4f opening=%.4f', $prev['closing'], $next['opening'])];
            $out[] = ["{$label}: identity opening + IN − OUT + TIN − TOUT + ADJ = closing in both months (closing value consistent)", abs((float) $prev['difference']) < 0.01 && abs((float) $next['difference']) < 0.01, sprintf('diff=%.4f / %.4f', $prev['difference'], $next['difference'])];
        }
    }
    foreach ($out as $k => $c) {
        $out[$k][3] = $c[3] ?? 'report';
    }
    $ok = count(array_filter($out, static fn ($c) => !$c[1])) === 0;
    return ['checks' => $out, 'status' => $ok ? ($required > 0 ? 'OVERRIDE_APPLIED' : 'NO_OVERRIDE_REQUIRED') : 'FAILED', 'required' => $required, 'table_exists' => $tableExists, 'override_rows' => count($ov), 'ledger_checksum' => $checksum];
}

/** @return list<array{0:string,1:bool,2:string}> */
function pc_verify(PDO $pdo, array $sessionIds, string $cutoff, ?string $expectChecksum = null): array
{
    return pc_verify_full($pdo, $sessionIds, $cutoff, $expectChecksum)['checks'];
}

/** Removes ONLY the override rows of the listed sessions (reports return to the original dates). @return array{removed:int,dropped:bool} */
function pc_rollback(PDO $pdo, array $sessionIds, bool $dropTable): array
{
    $in = implode(',', array_map('intval', $sessionIds));
    $res = Database::transaction(function (PDO $tx) use ($in) {
        $n = $tx->exec("DELETE FROM inventory_effective_dates WHERE source_type = '" . PC_SOURCE . "' AND source_id IN ({$in})");
        AuditService::log($tx, null, 'period_cutoff_cli', 'PERIOD_CUTOFF_ROLLBACK', 'inventory_effective_dates', null, null, ['sessions' => $in, 'removed' => $n], 'period cutoff rollback');
        return $n;
    });
    $dropped = false;
    if ($dropTable && (int) $pdo->query('SELECT COUNT(*) FROM inventory_effective_dates')->fetchColumn() === 0) {
        $pdo->exec('DROP TABLE inventory_effective_dates');
        $dropped = true;
    }
    InventoryEffectiveDateService::resetCache();
    return ['removed' => (int) $res, 'dropped' => $dropped];
}
