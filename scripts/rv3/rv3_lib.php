<?php
declare(strict_types=1);

/**
 * Reports v3 package — shared library of the deploy engine (PHP only, no python / node needed on the server).
 *
 * The package changes a handful of files. Every change is an OPERATION on one target file; operations are PURE FUNCTIONS of the file's current content:
 *     rv3_fold_file(content|null, ops) -> [newContent|null, [ {id, state, note} ... ]]
 * with state ∈ done (already in the wanted state) | todo (this op will change the file) | conflict (an unknown state: the engine STOPS, nothing is written).
 * Because they are pure, the same code gives the preflight report, the dry-run plan (bound to exact SHA256 hashes), the apply, the idempotence proof ("everything done")
 * and the post-apply verification.
 *
 * Operation types (manifest.json):
 *   file          whole-file install. absent -> install; identical -> done; equal to a KNOWN earlier version of the same file (hashes taken from the project history) -> replace; else conflict.
 *   css_block     a marker-delimited block in app.css. absent -> append at the end; present and identical -> done; present but different -> replace between OUR markers; broken markers -> conflict.
 *   php_include   one marker-delimited block inserted immediately before an anchor line of public/index.php (same marker semantics as css_block).
 *   app_route     re-points `<AnyGlobal>.render(document.getElementById('tab-X')…);` in app.js to the new page (exactly one statement per tab; zero -> conflict unless optional).
 *   html_scripts  every listed <script src="assets/js/FILE?v=TOKEN"> exactly once (inserted before the app.js tag; an existing tag only gets the new token; duplicates -> conflict).
 *   html_tokens   cache-bust tokens of the app.js script tag and the app.css link.
 *   html_sidebar  the Laporan menu = the five approved reports; every other report link is kept hidden in the DOM (route compatibility).
 */

const RV3_APPLY_ORDER = ['file', 'css_block', 'app_route', 'php_include', 'html_scripts', 'html_tokens', 'html_sidebar'];

function rv3_sha(string $s): string
{
    return hash('sha256', $s);
}

function rv3_read(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }
    $d = file_get_contents($path);
    return $d === false ? null : $d;
}

function rv3_die(string $msg, int $code = 1): never
{
    fwrite(STDERR, "FAILED: {$msg}\n");
    exit($code);
}

/** @return array{0:?string,1:list<array{id:string,state:string,note:string}>} */
function rv3_fold_file(?string $content, array $ops, string $payloadDir): array
{
    $report = [];
    foreach ($ops as $op) {
        $fn = 'rv3_op_' . $op['type'];
        if (!function_exists($fn)) {
            rv3_die("unknown operation type {$op['type']}");
        }
        [$content, $state, $note] = $fn($content, $op, $payloadDir);
        $report[] = ['id' => $op['id'], 'state' => $state, 'note' => $note];
    }
    return [$content, $report];
}

function rv3_payload(array $op, string $payloadDir): string
{
    $p = rtrim($payloadDir, '/') . '/' . $op['payload'];
    $d = rv3_read($p);
    if ($d === null) {
        rv3_die("payload file missing: {$op['payload']}");
    }
    if (!hash_equals($op['sha256'], rv3_sha($d))) {
        rv3_die("payload {$op['payload']} does not match the manifest SHA256 (corrupt or edited package)");
    }
    return $d;
}

// ---------------------------------------------------------------- file
function rv3_op_file(?string $cur, array $op, string $dir): array
{
    $new = rv3_payload($op, $dir);
    if ($cur === null) {
        return [$new, 'todo', 'absent -> install'];
    }
    $h = rv3_sha($cur);
    if (hash_equals($op['sha256'], $h)) {
        return [$cur, 'done', 'identical'];
    }
    if (in_array($h, $op['known'] ?? [], true)) {
        return [$new, 'todo', 'known earlier version (' . substr($h, 0, 12) . ') -> replace'];
    }
    return [$cur, 'conflict', 'UNKNOWN version on the server (sha256 ' . $h . ') — not a version this project ever produced'];
}

// ---------------------------------------------------------------- marker blocks (css_block, php_include)
/** @return array{0:?string,1:string,2:string} content, state, note */
function rv3_marker_block(?string $cur, array $op, string $block, ?string $insertBefore): array
{
    $cur ??= '';
    $b = $op['begin'];
    $e = $op['end'];
    $nb = substr_count($cur, $b);
    $ne = substr_count($cur, $e);
    if ($nb === 0 && $ne === 0) {
        if ($insertBefore === null) {
            $base = $cur === '' || str_ends_with($cur, "\n") ? $cur : $cur . "\n";
            return [$base . "\n" . $block . "\n", 'todo', 'absent -> append'];
        }
        if (substr_count($cur, $insertBefore) !== 1) {
            return [$cur, 'conflict', 'anchor line "' . trim($insertBefore) . '" found ' . substr_count($cur, $insertBefore) . ' time(s), expected exactly once'];
        }
        return [str_replace($insertBefore, $block . "\n\n" . $insertBefore, $cur), 'todo', 'absent -> insert before the anchor'];
    }
    if ($nb !== 1 || $ne !== 1) {
        return [$cur, 'conflict', "marker pair broken (begin x{$nb}, end x{$ne})"];
    }
    $p0 = strpos($cur, $b);
    $p1 = strpos($cur, $e);
    if ($p1 < $p0) {
        return [$cur, 'conflict', 'end marker precedes the begin marker'];
    }
    $existing = substr($cur, $p0, $p1 + strlen($e) - $p0);
    if ($existing === $block) {
        return [$cur, 'done', 'identical'];
    }
    return [substr($cur, 0, $p0) . $block . substr($cur, $p1 + strlen($e)), 'todo', 'present but different -> replace between our markers'];
}

function rv3_op_css_block(?string $cur, array $op, string $dir): array
{
    return rv3_marker_block($cur, $op, rtrim(rv3_payload($op, $dir), "\n"), null);
}

function rv3_op_php_include(?string $cur, array $op, string $dir): array
{
    $block = rtrim($op['text'], "\n");
    return rv3_marker_block($cur, $op, $block, $op['anchor']);
}

// ---------------------------------------------------------------- app.js routes
function rv3_op_app_route(?string $cur, array $op, string $dir): array
{
    if ($cur === null) {
        return [$cur, 'conflict', 'app.js is missing'];
    }
    $re = '/\b[A-Za-z_]\w*\.render\(document\.getElementById\(\'tab-' . preg_quote($op['tab'], '/') . '\'\)(?:,\s*\{[^}]*\})?\);/';
    $n = preg_match_all($re, $cur, $m);
    if ($n === 0) {
        return !empty($op['optional']) ? [$cur, 'done', 'route not present on this server (optional)'] : [$cur, 'conflict', "no render statement for tab-{$op['tab']} found in app.js"];
    }
    if ($n > 1) {
        return [$cur, 'conflict', "{$n} render statements for tab-{$op['tab']} (expected exactly one)"];
    }
    if ($m[0][0] === $op['statement']) {
        return [$cur, 'done', 'identical'];
    }
    return [preg_replace($re, strtr($op['statement'], ['\\' => '\\\\', '$' => '\\$']), $cur, 1), 'todo', 'was `' . $m[0][0] . '`'];
}

// ---------------------------------------------------------------- index.html
function rv3_script_re(string $file): string
{
    return '#<script\s+src="assets/js/' . preg_quote($file, '#') . '(?:\?v=[^"]*)?"\s*>\s*</script>#';
}

function rv3_op_html_scripts(?string $cur, array $op, string $dir): array
{
    if ($cur === null) {
        return [$cur, 'conflict', 'index.html is missing'];
    }
    $changed = false;
    $notes = [];
    $anchorRe = rv3_script_re('app.js');
    if (preg_match_all($anchorRe, $cur) !== 1) {
        return [$cur, 'conflict', 'the app.js <script> tag was found ' . preg_match_all($anchorRe, $cur) . ' time(s), expected exactly once'];
    }
    $missing = [];
    foreach ($op['tags'] as $t) {
        $re = rv3_script_re($t['file']);
        $n = preg_match_all($re, $cur, $mm);
        $tag = '<script src="assets/js/' . $t['file'] . '?v=' . $t['token'] . '"></script>';
        if ($n > 1) {
            return [$cur, 'conflict', "{$n} <script> tags for {$t['file']} (duplicate)"];
        }
        if ($n === 1) {
            if ($mm[0][0] !== $tag) {
                $cur = preg_replace($re, strtr($tag, ['\\' => '\\\\', '$' => '\\$']), $cur, 1);
                $changed = true;
                $notes[] = "{$t['file']}: token";
            }
        } else {
            $missing[] = $tag;
        }
    }
    if ($missing) {
        $cur = preg_replace_callback($anchorRe, static fn (array $m) => implode("\n", $missing) . "\n" . $m[0], $cur, 1);
        $changed = true;
        $notes[] = count($missing) . ' tag(s) inserted before app.js';
    }
    return [$cur, $changed ? 'todo' : 'done', $changed ? implode('; ', $notes) : 'all tags present once with the new token'];
}

function rv3_op_html_tokens(?string $cur, array $op, string $dir): array
{
    if ($cur === null) {
        return [$cur, 'conflict', 'index.html is missing'];
    }
    $changed = false;
    foreach ($op['assets'] as $a) {
        $re = '#(<(?:script|link)\b[^>]*?(?:src|href)=")(' . preg_quote($a['path'], '#') . ')(?:\?v=[^"]*)?(")#';
        $n = preg_match_all($re, $cur);
        if ($n !== 1) {
            return [$cur, 'conflict', "{$a['path']} referenced {$n} time(s) in index.html, expected exactly once"];
        }
        $new = preg_replace_callback($re, static fn (array $m) => $m[1] . $m[2] . '?v=' . $a['token'] . $m[3], $cur, 1);
        if ($new !== $cur) {
            $changed = true;
            $cur = $new;
        }
    }
    return [$cur, $changed ? 'todo' : 'done', $changed ? 'cache-bust tokens bumped' : 'tokens already current'];
}

/** @return array{0:int,1:int}|null start/end (exclusive) of the balanced element that starts at $open (position of "<div") */
function rv3_div_span(string $html, int $open): ?array
{
    $depth = 0;
    $pos = $open;
    while (preg_match('#<(/?)div\b[^>]*>#', $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
        $depth += $m[1][0] === '/' ? -1 : 1;
        $pos = $m[0][1] + strlen($m[0][0]);
        if ($depth === 0) {
            return [$open, $pos];
        }
    }
    return null;
}

/** @return list<array{tab:string,markup:string}> */
function rv3_anchors(string $html): array
{
    preg_match_all('#<a\b[^>]*\bdata-tab="([^"]+)"[^>]*>.*?</a>#s', $html, $m, PREG_SET_ORDER);
    return array_map(static fn (array $x) => ['tab' => $x[1], 'markup' => $x[0]], $m);
}

function rv3_op_html_sidebar(?string $cur, array $op, string $dir): array
{
    if ($cur === null) {
        return [$cur, 'conflict', 'index.html is missing'];
    }
    $approved = $op['approved'];                 // list of {tab, markup}  — the five approved reports
    $ind = '                    ';
    $legacyKnown = [];
    foreach ($op['legacy'] as $a) {              // the hidden links this project keeps (route compatibility), canonical markup
        $legacyKnown[$a['tab']] = $a['markup'];
    }
    $approvedTabs = array_column($approved, 'tab');
    if (!preg_match('#<div class="sidebar-group[^"]*"\s+data-group="laporan">#', $cur, $gm, PREG_OFFSET_CAPTURE)) {
        return [$cur, 'conflict', 'the Laporan sidebar group (data-group="laporan") was not found'];
    }
    $gStart = $gm[0][1];
    $gSpan = rv3_div_span($cur, $gStart);
    if ($gSpan === null || !preg_match('#<div class="sidebar-submenu"[^>]*>#', substr($cur, $gStart, $gSpan[1] - $gStart), $sm, PREG_OFFSET_CAPTURE)) {
        return [$cur, 'conflict', 'the Laporan sidebar submenu could not be parsed'];
    }
    $sStart = $gStart + $sm[0][1];
    $sSpan = rv3_div_span($cur, $sStart);
    $innerStart = $sStart + strlen($sm[0][0]);
    $innerEnd = $sSpan[1] - strlen('</div>');
    $extra = [];                                  // links this project does not know: kept, hidden
    $hide = static fn (string $markup): string => str_contains(substr($markup, 0, strpos($markup, '>')), 'tabindex=') ? $markup : preg_replace('#^<a\b#', '<a tabindex="-1"', $markup, 1);
    foreach (rv3_anchors(substr($cur, $innerStart, $innerEnd - $innerStart)) as $a) {
        if (!in_array($a['tab'], $approvedTabs, true) && !isset($legacyKnown[$a['tab']])) {
            $extra[$a['tab']] = $hide($a['markup']);
        }
    }
    $newInner = "\n";
    foreach ($approved as $a) {
        $newInner .= $ind . $a['markup'] . "\n";
    }
    $out = substr($cur, 0, $innerStart) . $newInner . '                ' . substr($cur, $innerEnd);

    $legacyOpen = '<div class="sidebar-legacy-routes"';
    $cPos = strpos($out, $legacyOpen);
    $note = '';
    if ($cPos !== false) {
        $cSpan = rv3_div_span($out, $cPos);
        foreach (rv3_anchors(substr($out, $cPos, $cSpan[1] - $cPos)) as $a) {
            if (!in_array($a['tab'], $approvedTabs, true) && !isset($legacyKnown[$a['tab']])) {
                $extra[$a['tab']] ??= $hide($a['markup']);
            }
        }
        $out = substr($out, 0, $cPos) . rv3_legacy_container($legacyKnown + $extra, "                ") . substr($out, $cSpan[1]);
    } else {
        $after = rv3_div_span($out, $gStart)[1];
        $out = substr($out, 0, $after) . "\n            " . $op['legacy_comment_block'] . "\n            " . rv3_legacy_container($legacyKnown + $extra, "                ") . substr($out, $after);
        $note = ' (hidden container created)';
    }
    // an "opname-laporan" link left OUTSIDE the hidden container (the old Stock Opname group) becomes a comment: the same data-tab lives in the hidden container
    $cPos = strpos($out, $legacyOpen);
    $cSpan = rv3_div_span($out, $cPos);
    $stray = 0;
    $strayRe = '#[ \t]*<a\b[^>]*\bdata-tab="opname-laporan"[^>]*>.*?</a>[ \t]*\n?#s';
    $repl = static function () use (&$stray, $op): string {
        $stray++;
        return '                    ' . $op['opname_comment'] . "\n";
    };
    $out = preg_replace_callback($strayRe, $repl, substr($out, 0, $cPos)) . substr($out, $cPos, $cSpan[1] - $cPos) . preg_replace_callback($strayRe, $repl, substr($out, $cSpan[1]));
    if ($out === $cur) {
        return [$cur, 'done', 'Laporan menu already = the five approved reports'];
    }
    return [$out, 'todo', 'Laporan menu -> the five approved reports, every other report link kept hidden' . $note . ($stray ? "; {$stray} stray opname-laporan link replaced by a comment" : '')];
}

/** @param array<string,string> $anchors tab => markup */
function rv3_legacy_container(array $anchors, string $ind): string
{
    $s = '<div class="sidebar-legacy-routes" data-testid="sidebar-legacy-routes" hidden aria-hidden="true">' . "\n";
    foreach ($anchors as $markup) {
        $s .= $ind . $markup . "\n";
    }
    return $s . '            </div>';
}

function rv3_mkdir(string $d): void
{
    if (!is_dir($d) && !mkdir($d, 0755, true) && !is_dir($d)) {
        rv3_die("cannot create directory {$d}");
    }
}

/**
 * Load order of the services for code under test: every production services/*.php EXCEPT those the package replaces (the payload copy is loaded instead), then the payload-only files.
 * Absolute paths, nothing is copied or linked — works on shared hosting where symlink() / link() are disabled, and never writes anywhere. Database.php (hence config/, storage/) always
 * comes from the production tree, so paths resolved relative to it are the real ones.
 * @return list<string>
 */
function rv3_service_files(string $app, ?string $payloadServices): array
{
    $files = [];
    $payload = [];
    foreach ($payloadServices !== null ? (glob(rtrim($payloadServices, '/') . '/*.php') ?: []) : [] as $f) {
        $payload[basename($f)] = $f;
    }
    foreach (glob(rtrim($app, '/') . '/services/*.php') ?: [] as $f) {
        $b = basename($f);
        if ($b === 'ReportsV3Routes.php') {
            continue;
        }
        $files[] = $payload[$b] ?? $f;
        unset($payload[$b]);
    }
    foreach ($payload as $b => $f) {
        if ($b !== 'ReportsV3Routes.php') {
            $files[] = $f;
        }
    }
    return $files;
}

/** the php binary for child processes: PHP_BIN, else the running binary (unless it is a cgi / fpm one), else "php"; PHP_ARGS (extra ini flags) is passed through. */
function rv3_php_cmd(): string
{
    $b = getenv('PHP_BIN') ?: PHP_BINARY;
    if ($b === '' || str_contains(basename($b), 'cgi') || str_contains(basename($b), 'fpm')) {
        $b = 'php';
    }
    return escapeshellarg($b) . (getenv('PHP_ARGS') ? ' ' . getenv('PHP_ARGS') : '');
}

/** runs a shell command; tries exec, shell_exec, proc_open, popen in turn (hosts disable different ones). @return array{0:int,1:string} exit code, combined output */
function rv3_run(string $cmd): array
{
    $dis = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $ok = static fn (string $f): bool => function_exists($f) && !in_array($f, $dis, true);
    if ($ok('exec')) {
        $o = [];
        $rc = 0;
        exec($cmd . ' 2>&1', $o, $rc);
        return [$rc, implode("\n", $o)];
    }
    if ($ok('proc_open')) {
        $h = proc_open($cmd . ' 2>&1', [1 => ['pipe', 'w']], $pipes);
        if (is_resource($h)) {
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            return [proc_close($h), $out];
        }
    }
    if ($ok('popen')) {
        $h = popen($cmd . ' 2>&1', 'r');
        if (is_resource($h)) {
            $out = '';
            while (!feof($h)) {
                $out .= (string) fread($h, 8192);
            }
            return [pclose($h), $out];
        }
    }
    if ($ok('shell_exec')) {
        $out = (string) shell_exec('(' . $cmd . ') 2>&1; echo "__RC=$?"');
        $rc = preg_match('/__RC=(\d+)\s*$/', $out, $m) ? (int) $m[1] : 1;
        return [$rc, (string) preg_replace('/__RC=\d+\s*$/', '', $out)];
    }
    return [127, 'no way to start a child process on this host (exec, proc_open, popen and shell_exec are all disabled)'];
}
