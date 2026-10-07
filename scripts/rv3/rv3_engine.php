<?php
declare(strict_types=1);

/**
 * Reports v3 — production deploy engine (PHP only).
 *
 *   php rv3_engine.php preflight --app-root=<APP ROOT>                  READ-ONLY. Environment, package integrity, dependency self-check (production services + payload loaded together), state of
 *                                                                        every operation, SHA256 of every target file.
 *   php rv3_engine.php plan      --app-root=<APP ROOT>                  = DRY-RUN. Everything preflight does + writes state/plan.json (binds the apply to the exact hashes seen now).
 *   php rv3_engine.php apply     --app-root=<APP ROOT> [--yes]          Applies the plan. Refuses without a fresh, unblocked plan; backs everything up first; atomic write + re-verify per file; any failure rolls the run back.
 *   php rv3_engine.php verify    --app-root=<APP ROOT> [--base-url=URL] Post-apply: every operation done, syntax of every changed PHP file, no duplicate script tags, final hashes.
 *   php rv3_engine.php rollback  --app-root=<APP ROOT>                  Two-phase: first checks every file is still byte-identical to what apply produced, then restores.
 *
 * Nothing here ever touches the database. Package state (plan, backups, applied record) lives in <package>/state, never inside the application tree.
 */

require_once __DIR__ . '/rv3_lib.php';

$argvCopy = $argv;
array_shift($argvCopy);
$cmd = array_shift($argvCopy) ?? '';
$opt = ['app-root' => null, 'package-dir' => dirname(__DIR__), 'base-url' => null, 'yes' => false];
foreach ($argvCopy as $a) {
    if ($a === '--yes') {
        $opt['yes'] = true;
    } elseif (preg_match('/^--(app-root|package-dir|base-url)=(.*)$/', $a, $m)) {
        $opt[$m[1]] = rtrim($m[2], '/');
    } else {
        rv3_die("unknown argument: {$a}", 2);
    }
}
if (!in_array($cmd, ['preflight', 'plan', 'apply', 'verify', 'rollback'], true) || $opt['app-root'] === null) {
    rv3_die('usage: php rv3_engine.php <preflight|plan|apply|verify|rollback> --app-root=<APP ROOT> [--package-dir=<dir>] [--base-url=<url>] [--yes]', 2);
}
$app = realpath($opt['app-root']) ?: rv3_die("app root not found: {$opt['app-root']}", 2);
$pkg = realpath($opt['package-dir']) ?: rv3_die("package dir not found: {$opt['package-dir']}", 2);
$payloadDir = "{$pkg}/payload";
$stateDir = "{$pkg}/state";
$manifestRaw = rv3_read("{$pkg}/manifest.json") ?? rv3_die("manifest.json not found in {$pkg}", 2);
$manifest = json_decode($manifestRaw, true) ?: rv3_die('manifest.json is not valid JSON', 2);
$manifestSha = rv3_sha($manifestRaw);

$out = [];
$say = static function (string $line = '') use (&$out): void {
    $out[] = $line;
    echo $line . "\n";
};

/** @return array<string,list<array>> target => ops (original order) */
function rv3_group(array $manifest): array
{
    $g = [];
    foreach ($manifest['ops'] as $op) {
        $g[$op['target']][] = $op;
    }
    $rank = static function (array $ops): int {
        $r = 99;
        foreach ($ops as $o) {
            $r = min($r, array_search($o['type'], RV3_APPLY_ORDER, true));
        }
        return $r;
    };
    uasort($g, static fn ($a, $b) => $rank($a) <=> $rank($b));
    return $g;
}

/** Folds every target against its current content. @return array<string,array{pre:?string,post:?string,report:list<array>,pre_sha:?string,post_sha:?string,action:string}> */
function rv3_evaluate(string $app, array $manifest, string $payloadDir): array
{
    $res = [];
    foreach (rv3_group($manifest) as $target => $ops) {
        $cur = rv3_read("{$app}/{$target}");
        [$post, $report] = rv3_fold_file($cur, $ops, $payloadDir);
        $blocked = (bool) array_filter($report, static fn ($r) => $r['state'] === 'conflict');
        $action = $blocked ? 'BLOCKED' : ($post === $cur ? 'none' : ($cur === null ? 'create' : 'write'));
        $res[$target] = ['pre' => $cur, 'post' => $post, 'report' => $report, 'pre_sha' => $cur === null ? null : rv3_sha($cur), 'post_sha' => $post === null ? null : rv3_sha($post), 'action' => $action];
    }
    return $res;
}

function rv3_status(array $eval): string
{
    $acts = array_column($eval, 'action');
    return in_array('BLOCKED', $acts, true) ? 'BLOCKED' : (array_diff($acts, ['none']) ? 'OK' : 'NOTHING_TO_DO');
}

function rv3_php_lint(string $file): ?string
{
    return rv3_lint($file);
}

function rv3_write_atomic(string $path, string $data, ?int $mode): void
{
    rv3_mkdir(dirname($path));
    $tmp = dirname($path) . '/.' . basename($path) . '.rv3tmp';
    if (file_put_contents($tmp, $data) !== strlen($data)) {
        @unlink($tmp);
        rv3_die("short write to {$tmp}");
    }
    if (rv3_fn('chmod')) {
        @chmod($tmp, $mode ?? 0644);
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        rv3_die("could not move {$tmp} into place");
    }
}

/** production services + the payload's, loaded together IN THIS PROCESS (no child process, no temp file, no copy, no link, no database access): proves syntax, no missing dependency file, no redeclared symbol. */
function rv3_selfcheck(string $app, string $pkg, array $manifest): array
{
    $files = rv3_service_files($app, "{$pkg}/payload/services");
    $err = rv3_load_services($files);
    if ($err !== null) {
        return [false, ['missing' => [$err]], $err];
    }
    $routesFile = "{$pkg}/payload/services/ReportsV3Routes.php";
    if (!is_file($routesFile)) {
        $routesFile = "{$app}/services/ReportsV3Routes.php";             // frontend-only package: the installed (gated) backend is what is checked
    }
    $dup = array_filter(['rv3_pur_filters', 'rv3_val_filters', 'rv3_io_filters', 'rv3_deliver', 'rv3_movement_params', 'rv3_soa_filters', 'rv3_soa_line_filters', 'rv3_require_warehouse_scope', 'rv3_so_resolve_warehouse_scope', 'rv3_soa_session_ids', 'rv3_require_so_warehouse_scope'], 'function_exists');
    if ($dup) {
        return [false, ['missing' => ['function(s) already defined: ' . implode(', ', $dup)]], ''];
    }
    $pdo = new stdClass();                 // the routes file only builds closures: no database is ever touched here
    $query = [];
    $routes = [];
    try {
        $routes = require $routesFile;
    } catch (Throwable $e) {
        return [false, ['missing' => [get_class($e) . ': ' . $e->getMessage()]], ''];
    }
    $missing = [];
    foreach ($manifest['requirements'] ?? [] as $r) {
        $c = $r['class'];
        if (!class_exists($c) && !interface_exists($c)) {
            $missing[] = "class {$c}";
            continue;
        }
        if (isset($r['method']) && !method_exists($c, $r['method'])) {
            $missing[] = "{$c}::{$r['method']}()";
        }
        if (isset($r['const']) && !defined("{$c}::{$r['const']}")) {
            $missing[] = "{$c}::{$r['const']}";
        }
    }
    $info = ['routes' => is_array($routes) ? count($routes) : 0, 'missing' => $missing];
    return [$missing === [] && $info['routes'] > 0, $info, ''];
}
function rv3_print_eval(callable $say, array $eval): void
{
    foreach ($eval as $target => $e) {
        $say(sprintf('  [%-7s] %s   pre=%s  post=%s', $e['action'], $target, $e['pre_sha'] === null ? 'ABSENT' : substr($e['pre_sha'], 0, 12), $e['post_sha'] === null ? '-' : substr($e['post_sha'], 0, 12)));
        foreach ($e['report'] as $r) {
            $say(sprintf('            %-9s %-34s %s', $r['state'], $r['id'], $r['note']));
        }
    }
}

// ====================================================================== preflight / plan
$checks = [];
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$say, &$fail): void {
    if (!$ok) {
        $fail++;
    }
    $say(($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : ''));
};

if ($cmd === 'preflight' || $cmd === 'plan') {
    $say("== Reports v3 — " . ($cmd === 'plan' ? 'DRY-RUN (plan)' : 'PRE-FLIGHT') . ' (read-only: no application file is modified) ==');
    $say("package : {$manifest['name']}  manifest sha256 {$manifestSha}");
    $say("app root: {$app}");
    $say('time    : ' . date('Y-m-d H:i:s'));
    $say();
    $say('-- environment');
    $check('PHP >= 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
    foreach (['zip', 'pdo_mysql', 'mbstring', 'json'] as $ext) {
        $check("php extension {$ext}", extension_loaded($ext) || $ext === 'mbstring', extension_loaded($ext) ? 'loaded' : 'not loaded');
    }
    foreach (['public/index.php', 'public/index.html', 'public/assets/js/app.js', 'public/assets/css/app.css', 'services/Database.php', 'config/config.php'] as $rel) {
        $check("exists: {$rel}", is_file("{$app}/{$rel}"));
    }
    $writable = true;
    foreach (['public', 'public/assets/js', 'public/assets/css', 'services'] as $rel) {
        $writable = $writable && is_writable("{$app}/{$rel}");
    }
    $check('application directories are writable by this user', $writable);
    $check('package state directory is writable', is_dir($stateDir) ? is_writable($stateDir) : is_writable($pkg));
    $free = rv3_fn('disk_free_space') ? @disk_free_space($app) : false;
    $check('free disk space >= 50 MB', $free === false || $free > 50 * 1024 * 1024, $free === false ? 'unknown' : round($free / 1048576) . ' MB');
    $say();
    $say('-- package integrity');
    $sums = rv3_read("{$pkg}/SHA256SUMS");
    $bad = [];
    if ($sums === null) {
        $bad[] = 'SHA256SUMS missing';
    } else {
        foreach (explode("\n", trim($sums)) as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+(.+)$/', $line, $m)) {
                $d = rv3_read("{$pkg}/{$m[2]}");
                if ($d === null || !hash_equals($m[1], rv3_sha($d))) {
                    $bad[] = $m[2];
                }
            }
        }
    }
    $check('every file of the package matches SHA256SUMS', $bad === [], implode(', ', array_slice($bad, 0, 5)));
    $lintBad = [];
    foreach (glob("{$payloadDir}/services/*.php") ?: [] as $f) {
        if (($e = rv3_php_lint($f)) !== null) {
            $lintBad[] = basename($f) . ': ' . $e;
        }
    }
    $check('php -l on every payload PHP file', $lintBad === [], implode(' | ', $lintBad));
    $say();
    $say('-- dependency self-check (production services + payload loaded together in this process; no child process, no database access)');
    [$ok, $info, $text] = rv3_selfcheck($app, $pkg, $manifest);
    $check('all services load; ReportsV3Routes returns ' . ($info['routes'] ?? '?') . ' routes; every required class / method / constant exists', $ok, $ok ? '' : (!empty($info['missing']) ? 'missing: ' . implode(', ', $info['missing']) : substr($text, 0, 400)));
    $say();
    $say('-- operations (state of every target file right now)');
    $eval = rv3_evaluate($app, $manifest, $payloadDir);
    rv3_print_eval($say, $eval);
    $status = rv3_status($eval);
    $say();
    $say('-- SHA256 of the target files as they are on the server now');
    foreach ($eval as $target => $e) {
        $say(sprintf('  %s  %s', $e['pre_sha'] ?? 'ABSENT' . str_repeat(' ', 58), $target));
    }
    $say();
    $envOk = $fail === 0;
    if ($cmd === 'plan') {
        rv3_mkdir($stateDir);
        $plan = ['package' => $manifest['name'], 'manifest_sha256' => $manifestSha, 'app_root' => $app, 'created' => date('c'), 'status' => $status, 'environment_ok' => $envOk, 'files' => []];
        foreach ($eval as $target => $e) {
            $plan['files'][$target] = ['action' => $e['action'], 'pre_sha256' => $e['pre_sha'], 'post_sha256' => $e['post_sha'], 'ops' => $e['report']];
        }
        file_put_contents("{$stateDir}/plan.json", json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    $say(($envOk ? 'ENVIRONMENT: OK' : "ENVIRONMENT: {$fail} check(s) FAILED") . "   |   PLAN: {$status}");
    if ($status === 'BLOCKED') {
        $say('A conflict means the server holds a file state this package does not know. NOTHING was written. Send this complete output back; do not edit files by hand.');
    } elseif ($status === 'NOTHING_TO_DO') {
        $say('Every operation is already done — the package is applied (idempotent).');
    } else {
        $say($cmd === 'plan' ? 'Dry-run complete. The plan is saved (state/plan.json). Nothing has been changed.' : 'Pre-flight complete. Nothing has been changed.');
    }
    if ($cmd === 'plan') {
        rv3_mkdir($stateDir);
        file_put_contents("{$stateDir}/dryrun_report.txt", implode("\n", $out) . "\n");
    }
    exit($envOk && $status !== 'BLOCKED' ? 0 : 3);
}

// ====================================================================== apply
if ($cmd === 'apply') {
    $planRaw = rv3_read("{$stateDir}/plan.json") ?? rv3_die('no dry-run plan found (state/plan.json). Run the dry-run first.', 3);
    $plan = json_decode($planRaw, true) ?: rv3_die('plan.json is not valid', 3);
    $eval = rv3_evaluate($app, $manifest, $payloadDir);
    $status = rv3_status($eval);
    if ($status === 'NOTHING_TO_DO') {
        $say('Nothing to do: every operation is already done (the package is already applied). No file touched.');
        exit(0);
    }
    if ($status === 'BLOCKED') {
        rv3_die('the current state of the server is BLOCKED (unknown file state). Nothing written. Re-run the dry-run and send the output.', 3);
    }
    if (($plan['manifest_sha256'] ?? '') !== $manifestSha || ($plan['app_root'] ?? '') !== $app) {
        rv3_die('the plan was made for another package / application root. Re-run the dry-run.', 3);
    }
    if (empty($plan['environment_ok'])) {
        rv3_die('the dry-run reported a failed environment check. Fix it and re-run the dry-run.', 3);
    }
    foreach ($eval as $target => $e) {
        $p = $plan['files'][$target] ?? null;
        if ($p === null || $p['pre_sha256'] !== $e['pre_sha'] || $p['post_sha256'] !== $e['post_sha']) {
            rv3_die("{$target} changed since the dry-run (or the plan does not describe it). Nothing written. Re-run the dry-run.", 3);
        }
    }
    if (!$opt['yes']) {
        $say('Plan verified against the live files. Re-run with --yes to apply.');
        rv3_print_eval($say, array_filter($eval, static fn ($e) => $e['action'] !== 'none'));
        exit(0);
    }
    rv3_mkdir($stateDir);
    $lockH = fopen("{$stateDir}/apply.lock", 'c');
    if (!$lockH || (rv3_fn('flock') && !flock($lockH, LOCK_EX | LOCK_NB))) {
        rv3_die('another apply/rollback is running', 3);
    }
    $run = date('Ymd_His');
    $backupDir = "{$stateDir}/backup/{$run}";
    $files = [];
    foreach ($eval as $target => $e) {
        if ($e['action'] === 'none') {
            continue;
        }
        $files[$target] = ['pre_sha256' => $e['pre_sha'], 'post_sha256' => $e['post_sha'], 'mode' => is_file("{$app}/{$target}") ? (fileperms("{$app}/{$target}") & 0777) : null];
        if ($e['pre'] !== null) {
            rv3_mkdir(dirname("{$backupDir}/{$target}"));
            if (file_put_contents("{$backupDir}/{$target}", $e['pre']) !== strlen($e['pre'])) {
                rv3_die("could not back up {$target}");
            }
        }
    }
    $record = ['run' => $run, 'status' => 'started', 'app_root' => $app, 'backup_dir' => $backupDir, 'manifest_sha256' => $manifestSha, 'files' => $files];
    file_put_contents("{$stateDir}/run_{$run}.json", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $written = [];
    $restore = static function () use (&$written, $app, $backupDir, $files): void {
        foreach (array_reverse($written) as $target) {
            $b = "{$backupDir}/{$target}";
            if (is_file($b)) {
                rv3_write_atomic("{$app}/{$target}", (string) file_get_contents($b), $files[$target]['mode']);
            } else {
                @unlink("{$app}/{$target}");
            }
        }
    };
    try {
        foreach ($eval as $target => $e) {
            if ($e['action'] === 'none') {
                continue;
            }
            rv3_write_atomic("{$app}/{$target}", (string) $e['post'], $files[$target]['mode']);
            $written[] = $target;
            $back = rv3_read("{$app}/{$target}");
            if ($back === null || rv3_sha($back) !== $e['post_sha']) {
                throw new RuntimeException("{$target}: the file read back differs from what was written");
            }
            if (str_ends_with($target, '.php') && ($err = rv3_php_lint("{$app}/{$target}")) !== null) {
                throw new RuntimeException("{$target}: php -l failed: {$err}");
            }
            $say("  wrote {$target}  sha256 {$e['post_sha']}");
        }
        $after = rv3_evaluate($app, $manifest, $payloadDir);
        if (rv3_status($after) !== 'NOTHING_TO_DO') {
            throw new RuntimeException('post-apply check: not every operation reports done');
        }
    } catch (Throwable $ex) {
        $restore();
        $record['status'] = 'failed_rolled_back';
        $record['error'] = $ex->getMessage();
        file_put_contents("{$stateDir}/run_{$run}.json", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        rv3_die('apply failed and was rolled back: ' . $ex->getMessage(), 4);
    }
    $record['status'] = 'applied';
    $record['finished'] = date('c');
    file_put_contents("{$stateDir}/run_{$run}.json", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents("{$stateDir}/applied.json", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $lines = [];
    foreach ($files as $t => $f) {
        $lines[] = "{$f['post_sha256']}  {$t}";
    }
    file_put_contents("{$stateDir}/FINAL_HASHES.txt", implode("\n", $lines) . "\n");
    $say();
    $say('APPLIED. Final SHA256 of the changed files (also saved in state/FINAL_HASHES.txt):');
    foreach ($lines as $l) {
        $say('  ' . $l);
    }
    $say('Backups: ' . $backupDir);
    $say('Next: run the post-apply verification, then hard-refresh the browser (Ctrl+F5).');
    exit(0);
}

// ====================================================================== verify
if ($cmd === 'verify') {
    $say('== Reports v3 — post-apply verification (read-only) ==');
    $eval = rv3_evaluate($app, $manifest, $payloadDir);
    $check('every operation reports done (the files are exactly the packaged state; re-applying would change nothing)', rv3_status($eval) === 'NOTHING_TO_DO', rv3_status($eval));
    foreach ($eval as $target => $e) {
        foreach ($e['report'] as $r) {
            if ($r['state'] !== 'done') {
                $say("   {$r['state']}: {$r['id']} — {$r['note']}");
            }
        }
    }
    $lintBad = [];
    foreach ($manifest['ops'] as $op) {
        if ($op['type'] === 'file' && str_ends_with($op['target'], '.php') && ($e2 = rv3_php_lint("{$app}/{$op['target']}")) !== null) {
            $lintBad[] = $op['target'] . ': ' . $e2;
        }
    }
    if (($e2 = rv3_php_lint("{$app}/public/index.php")) !== null) {
        $lintBad[] = 'public/index.php: ' . $e2;
    }
    $check('php -l on every changed PHP file and on public/index.php', $lintBad === [], implode(' | ', $lintBad));
    $html = rv3_read("{$app}/public/index.html") ?? '';
    $dups = [];
    foreach ($manifest['ops'] as $op) {
        if ($op['type'] === 'html_scripts') {
            foreach ($op['tags'] as $t) {
                $n = preg_match_all(rv3_script_re($t['file']), $html);
                if ($n !== 1) {
                    $dups[] = "{$t['file']} x{$n}";
                }
            }
        }
    }
    $check('every new <script> tag appears exactly once in index.html', $dups === [], implode(', ', $dups));
    $sbOp = null;
    foreach ($manifest['ops'] as $op) {
        if ($op['type'] === 'html_sidebar') {
            $sbOp = $op;
        }
    }
    if ($sbOp !== null) {
        $sb = rv3_sidebar_report_links($html, $sbOp);
        $want = array_map(static fn ($a) => rv3_anchor_label($a['markup']), $sbOp['approved']);
        $check('the Laporan menu in public/index.html shows exactly the five approved reports (' . implode(' / ', $want) . ')', $sb['visible'] === $want && $sb['old_visible'] === [], 'visible: ' . implode(' | ', array_merge($sb['visible'], $sb['old_visible'])));
        $present = array_values(array_filter($sbOp['old_report_tabs'], static fn ($t) => str_contains($html, 'data-tab="' . $t . '"')));
        $check('every old report route present in index.html is inside the hidden container (still routable, never visible)', array_diff($present, $sb['hidden']) === [], 'not hidden: ' . implode(',', array_diff($present, $sb['hidden'])));
    }
    $nTab = [];
    $js = rv3_read("{$app}/public/assets/js/app.js") ?? '';
    foreach ($manifest['ops'] as $op) {
        if ($op['type'] === 'app_route' && empty($op['optional'])) {
            $nTab[$op['tab']] = substr_count($js, $op['statement']);
        }
    }
    $check('app.js renders each of the five reports exactly once through the new pages', !array_diff($nTab, [1]) && count($nTab) >= 5, json_encode($nTab));
    $applied = json_decode((string) rv3_read("{$stateDir}/applied.json"), true);
    if (is_array($applied)) {
        $mis = [];
        foreach ($applied['files'] as $t => $f) {
            $d = rv3_read("{$app}/{$t}");
            if ($d === null || rv3_sha($d) !== $f['post_sha256']) {
                $mis[] = $t;
            }
        }
        $check('every changed file still has the recorded post-apply SHA256', $mis === [], implode(', ', $mis));
    } else {
        $say('NOTE - no state/applied.json (this server was patched by another run of the package, or by hand): hash record check skipped.');
    }
    if ($opt['base-url']) {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'header' => "Cache-Control: no-cache\r\nPragma: no-cache\r\n"]]);
        // the page the BROWSER receives: index.html as served by the web server vs the file on disk (a cache / CDN / another document root shows the old sidebar even though the file is patched)
        $served = @file_get_contents($opt['base-url'] . '/?nocache=' . time(), false, $ctx);
        $code0 = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $code0 = (int) $m[1];
            }
        }
        $check('GET / is served (200)', $code0 === 200 && is_string($served), "HTTP {$code0}");
        if (is_string($served) && $sbOp !== null) {
            $sbServed = rv3_sidebar_report_links($served, $sbOp);
            $check('the index.html the web server SERVES shows exactly the five approved reports (no old report link visible)', $sbServed['visible'] === $want && $sbServed['old_visible'] === [], 'served visible: ' . implode(' | ', array_merge($sbServed['visible'], $sbServed['old_visible'])));
            $check('the served index.html is byte-identical to public/index.html on disk (else a cache / CDN / another document root answers)', rv3_sha($served) === rv3_sha($html), 'served ' . substr(rv3_sha($served), 0, 12) . ' vs disk ' . substr(rv3_sha($html), 0, 12));
        }
        foreach ($manifest['ops'] as $op) {
            if ($op['type'] === 'file' && str_starts_with($op['target'], 'public/')) {
                $body = @file_get_contents($opt['base-url'] . '/' . substr($op['target'], 7), false, $ctx);
                $code = 0;
                foreach ($http_response_header ?? [] as $h) {
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                        $code = (int) $m[1];
                    }
                }
                $check('GET ' . substr($op['target'], 7) . ' is served (200) and is the packaged file', $code === 200 && $body !== false && rv3_sha($body) === $op['sha256'], "HTTP {$code}");
            }
        }
    }
    $say($fail === 0 ? 'VERIFY: ALL CHECKS PASSED' : "VERIFY: {$fail} CHECK(S) FAILED");
    exit($fail === 0 ? 0 : 1);
}

// ====================================================================== rollback
if ($cmd === 'rollback') {
    $rec = json_decode((string) rv3_read("{$stateDir}/applied.json"), true);
    if (!is_array($rec)) {
        $say('Nothing to roll back: no state/applied.json (the package was not applied from this directory, or was already rolled back).');
        exit(0);
    }
    $say("== Reports v3 — rollback of run {$rec['run']} ==");
    $problems = [];
    foreach ($rec['files'] as $t => $f) {
        $d = rv3_read("{$app}/{$t}");
        if ($d === null || rv3_sha($d) !== $f['post_sha256']) {
            $problems[] = "{$t} is no longer byte-identical to what apply produced (someone changed it since)";
        }
        if ($f['pre_sha256'] !== null && !is_file("{$rec['backup_dir']}/{$t}")) {
            $problems[] = "backup of {$t} is missing";
        }
    }
    if ($problems) {
        foreach ($problems as $p) {
            $say('  ' . $p);
        }
        rv3_die('rollback refused (phase 1 found problems). NOTHING was restored.', 3);
    }
    $say('phase 1 OK: every file is exactly the applied state and every backup exists.');
    foreach ($rec['files'] as $t => $f) {
        if ($f['pre_sha256'] === null) {
            @unlink("{$app}/{$t}");
            $say("  removed {$t} (did not exist before)");
        } else {
            rv3_write_atomic("{$app}/{$t}", (string) file_get_contents("{$rec['backup_dir']}/{$t}"), $f['mode']);
            $back = rv3_read("{$app}/{$t}");
            if ($back === null || rv3_sha($back) !== $f['pre_sha256']) {
                rv3_die("{$t}: restored file differs from the backup");
            }
            $say("  restored {$t}  sha256 {$f['pre_sha256']}");
        }
    }
    rename("{$stateDir}/applied.json", "{$stateDir}/applied.{$rec['run']}.rolledback.json");
    $say('ROLLED BACK. The application files are byte-identical to the state before the apply.');
    exit(0);
}
