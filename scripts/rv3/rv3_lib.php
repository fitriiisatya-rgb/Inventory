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

const RV3_APPLY_ORDER = ['require_file', 'require_marker', 'file', 'css_block', 'app_route', 'app_label', 'php_include', 'html_scripts', 'html_tokens', 'html_sidebar'];

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

/**
 * GATE (never writes): the file must already be one of the accepted versions. Used by the frontend-only package: the validated backend (services) must already be installed;
 * if it is not, the plan is BLOCKED instead of the package overwriting backend code it was not meant to touch.
 */
function rv3_op_require_file(?string $cur, array $op, string $dir): array
{
    if ($cur === null) {
        return [$cur, 'conflict', "required backend file {$op['target']} is not installed — apply the Reports v3 backend package first (this package changes the frontend only)"];
    }
    $sha = rv3_sha($cur);
    if (in_array($sha, $op['accept'], true)) {
        return [$cur, 'done', 'installed version is the validated one (gate, never written)'];
    }
    return [$cur, 'conflict', "backend file {$op['target']} is not at the validated version (found sha256 " . substr($sha, 0, 16) . "…, expected " . substr($op['accept'][0], 0, 16) . '…) — this frontend-only package will NOT overwrite backend code; apply the Reports v3 backend package first'];
}

/** GATE (never writes): the file must contain the marker text (e.g. the one-line Reports v3 route include in index.php). */
function rv3_op_require_marker(?string $cur, array $op, string $dir): array
{
    if ($cur === null || !str_contains($cur, $op['marker'])) {
        return [$cur, 'conflict', "{$op['target']} does not contain the Reports v3 marker \"{$op['marker']}\" — the Reports v3 backend is not installed; apply the backend package first"];
    }
    return [$cur, 'done', 'marker present (gate, never written)'];
}

/** the breadcrumb / page-title text of one tab in app.js's label map: 'laporan-hpp': 'Laporan Nilai HPP' */
function rv3_op_app_label(?string $cur, array $op, string $dir): array
{
    if ($cur === null) {
        return [$cur, 'conflict', 'app.js is missing'];
    }
    $re = '/(\'' . preg_quote($op['tab'], '/') . '\'\s*:\s*)\'([^\']*)\'/';
    $n = preg_match_all($re, $cur, $m);
    if ($n === 0) {
        return [$cur, 'done', 'no label entry for this tab on this server'];
    }
    if ($n > 1) {
        return [$cur, 'conflict', "{$n} label entries for {$op['tab']} (expected exactly one)"];
    }
    if ($m[2][0] === $op['label']) {
        return [$cur, 'done', 'identical'];
    }
    return [preg_replace($re, '$1\'' . strtr($op['label'], ['\\' => '\\\\', '$' => '\\$']) . '\'', $cur, 1), 'todo', 'was \'' . $m[2][0] . '\''];
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

/** the same HTML with every <!-- comment --> blanked to spaces (identical length, so offsets stay valid): a "</div>" or "<a" quoted inside a comment must never count */
function rv3_mask_comments(string $html): string
{
    return preg_replace_callback('#<!--.*?(?:-->|$)#s', static fn (array $m): string => str_repeat(' ', strlen($m[0])), $html) ?? $html;
}

/** @return array{0:int,1:int}|null start/end (exclusive) of the balanced element that starts at $open (position of "<div"); comments are ignored */
function rv3_div_span(string $html, int $open): ?array
{
    $html = rv3_mask_comments($html);
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

/** @return list<array{tab:string,markup:string}> anchors that carry a data-tab (anchors inside comments are not anchors) */
function rv3_anchors(string $html): array
{
    return array_map(static fn (array $x) => ['tab' => $x['tab'], 'markup' => $x['markup']], rv3_anchor_spans($html));
}

/** @return list<array{tab:string,markup:string,start:int,end:int}> */
function rv3_anchor_spans(string $html): array
{
    preg_match_all('#<a\b[^>]*\bdata-tab="([^"]+)"[^>]*>.*?</a>#s', rv3_mask_comments($html), $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $out = [];
    foreach ($m as $x) {
        $out[] = ['tab' => $x[1][0], 'markup' => substr($html, $x[0][1], strlen($x[0][0])), 'start' => $x[0][1], 'end' => $x[0][1] + strlen($x[0][0])];
    }
    return $out;
}

/** visible label of an anchor: icon span and tags removed, entities decoded, whitespace collapsed */
function rv3_anchor_label(string $markup): string
{
    $t = preg_replace('#<span\b[^>]*class="[^"]*\bicon\b[^"]*"[^>]*>.*?</span>#s', '', $markup) ?? $markup;
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('#\s+#u', ' ', $t) ?? $t);
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
    if (!preg_match('#<div class="sidebar-group[^"]*"\s+data-group="laporan">#', rv3_mask_comments($cur), $gm, PREG_OFFSET_CAPTURE)) {
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
    $cPos = strpos(rv3_mask_comments($out), $legacyOpen);
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
    $cPos = strpos(rv3_mask_comments($out), $legacyOpen);
    $cSpan = rv3_div_span($out, $cPos);
    $stray = 0;
    $strayRe = '#[ \t]*<a\b[^>]*\bdata-tab="opname-laporan"[^>]*>.*?</a>[ \t]*\n?#s';
    $repl = static function () use (&$stray, $op): string {
        $stray++;
        return '                    ' . $op['opname_comment'] . "\n";
    };
    $out = preg_replace_callback($strayRe, $repl, substr($out, 0, $cPos)) . substr($out, $cPos, $cSpan[1] - $cPos) . preg_replace_callback($strayRe, $repl, substr($out, $cSpan[1]));
    [$out, $swept] = rv3_sidebar_sweep($out, $op, $approvedTabs, $legacyKnown, $hide);
    if ($out === $cur) {
        return [$cur, 'done', 'Laporan menu already = the five approved reports'];
    }
    return [$out, 'todo', 'Laporan menu -> the five approved reports, every other report link kept hidden' . $note . ($stray ? "; {$stray} stray opname-laporan link replaced by a comment" : '')
        . ($swept['moved'] || $swept['dupes'] ? "; sweep: {$swept['moved']} old report link(s) moved into the hidden container, {$swept['dupes']} duplicate approved link(s) removed" : '')];
}

/**
 * Last pass over the whole sidebar (<nav id="sidebar">): whatever the production markup looked like, no old report link stays VISIBLE and no approved report appears twice.
 *   - an anchor of an old report (by data-tab OR by its visible label) outside the hidden container / the approved Laporan submenu  -> moved into the hidden container (route compatibility)
 *   - an approved report that appears a second time (a duplicate row, or a copy outside the Laporan submenu)                        -> removed
 * Pure function of its input: running it on its own output changes nothing.
 * @return array{0:string,1:array{moved:int,dupes:int}}
 */
function rv3_sidebar_sweep(string $out, array $op, array $approvedTabs, array $legacyKnown, callable $hide): array
{
    $stats = ['moved' => 0, 'dupes' => 0];
    $oldTabs = array_map('strval', $op['old_report_tabs'] ?? []);
    $oldLabels = array_map(static fn ($l) => mb_strtolower((string) $l), $op['old_report_labels'] ?? []);
    if (!$oldTabs && !$oldLabels) {
        return [$out, $stats];
    }
    $masked = rv3_mask_comments($out);
    $nStart = 0;
    $nEnd = strlen($out);
    if (preg_match('#<nav\b[^>]*\bid="sidebar"[^>]*>#', $masked, $nm, PREG_OFFSET_CAPTURE)) {
        $nStart = $nm[0][1];
        $nEnd = ($e = strpos($masked, '</nav>', $nStart)) === false ? $nEnd : $e;
    }
    $legacyOpen = strpos($masked, '<div class="sidebar-legacy-routes"');
    $legacy = $legacyOpen === false ? null : rv3_div_span($out, $legacyOpen);
    $gPos = preg_match('#<div class="sidebar-group[^"]*"\s+data-group="laporan">#', $masked, $gm, PREG_OFFSET_CAPTURE) ? $gm[0][1] : null;
    $group = $gPos === null ? null : rv3_div_span($out, $gPos);
    $seen = [];
    $remove = [];
    $moved = [];
    foreach (rv3_anchor_spans($out) as $a) {
        if ($a['start'] < $nStart || $a['end'] > $nEnd) {
            continue;
        }
        if ($legacy !== null && $a['start'] >= $legacy[0] && $a['end'] <= $legacy[1]) {
            continue;
        }
        $inGroup = $group !== null && $a['start'] >= $group[0] && $a['end'] <= $group[1];
        if (in_array($a['tab'], $approvedTabs, true)) {
            if ($inGroup && !isset($seen[$a['tab']])) {
                $seen[$a['tab']] = true;
                continue;
            }
            $remove[] = $a;
            $stats['dupes']++;
            continue;
        }
        $label = mb_strtolower(rv3_anchor_label($a['markup']));
        if (in_array($a['tab'], $oldTabs, true) || in_array($label, $oldLabels, true)) {
            $remove[] = $a;
            $moved[$a['tab']] = $legacyKnown[$a['tab']] ?? $hide($a['markup']);
            $stats['moved']++;
        }
    }
    if (!$remove) {
        return [$out, $stats];
    }
    foreach (array_reverse($remove) as $a) {                       // from the end: earlier offsets stay valid
        $ls = strrpos(substr($out, 0, $a['start']), "\n");
        $lead = $ls === false ? 0 : $ls + 1;
        $pre = substr($out, $lead, $a['start'] - $lead);
        $nl = strpos($out, "\n", $a['end']);
        $post = $nl === false ? substr($out, $a['end']) : substr($out, $a['end'], $nl - $a['end']);
        $wholeLine = trim($pre) === '' && trim($post) === '' && $nl !== false;
        $out = $wholeLine ? substr($out, 0, $lead) . substr($out, $nl + 1) : substr($out, 0, $a['start']) . substr($out, $a['end']);
    }
    if ($moved) {
        $cPos = strpos(rv3_mask_comments($out), '<div class="sidebar-legacy-routes"');
        if ($cPos === false) {
            return [$out, $stats];                                  // no hidden container (cannot happen after the container pass); nothing to add the moved links to
        }
        $cSpan = rv3_div_span($out, $cPos);
        $inner = [];
        foreach (rv3_anchors(substr($out, $cPos, $cSpan[1] - $cPos)) as $a) {
            $inner[$a['tab']] = $a['markup'];
        }
        $out = substr($out, 0, $cPos) . rv3_legacy_container($inner + $moved, '                ') . substr($out, $cSpan[1]);
    }
    return [$out, $stats];
}

/** the html_sidebar op payload (approved five, hidden legacy links, comments, old-report tabs / labels) parsed out of the committed dev index.html */
function rv3_sidebar_op_from_html(string $html): array
{
    preg_match('#<div class="sidebar-group[^"]*"\s+data-group="laporan">#', rv3_mask_comments($html), $gm, PREG_OFFSET_CAPTURE);
    $gSpan = rv3_div_span($html, $gm[0][1]);
    preg_match('#<div class="sidebar-submenu"[^>]*>#', substr($html, $gm[0][1], $gSpan[1] - $gm[0][1]), $sm, PREG_OFFSET_CAPTURE);
    $sStart = $gm[0][1] + $sm[0][1];
    $sSpan = rv3_div_span($html, $sStart);
    $approved = rv3_anchors(substr($html, $sStart, $sSpan[1] - $sStart));
    $cPos = strpos(rv3_mask_comments($html), '<div class="sidebar-legacy-routes"');
    $cSpan = rv3_div_span($html, $cPos);
    $legacy = rv3_anchors(substr($html, $cPos, $cSpan[1] - $cPos));
    if (array_column($approved, 'tab') !== ['laporan-pergerakan', 'laporan-inout', 'laporan-pembelian', 'laporan-hpp', 'laporan-opname']) {
        rv3_die('dev index.html: the Laporan submenu is not the five approved reports in order: ' . implode(',', array_column($approved, 'tab')));
    }
    $cm0 = strrpos(substr($html, 0, $cPos), '<!-- Sidebar cleanup');
    $legacyComment = $cm0 === false ? '' : trim(substr($html, $cm0, $cPos - $cm0));
    preg_match('#<!-- "Laporan Stock Opname" \(data-tab opname-laporan\).*?-->#s', $html, $oc);
    // every old report entry the sidebar may still carry (by route AND by visible label); the sweep moves them into the hidden container wherever they sit
    $oldReportTabs = ['laporan-ringkasan', 'laporan-stok', 'laporan-transfer', 'opname-laporan', 'laporan-adjustment', 'laporan-expiry', 'laporan-supplier', 'laporan-bakery', 'laporan-slow-movement', 'laporan-rekonsiliasi', 'laporan-audit',
        'laporan-movement', 'laporan-pergerakan-harian', 'laporan-nilai-stok', 'laporan-stock-opname', 'laporan-jejak'];
    $oldReportLabels = ['Ringkasan Inventory', 'Pergerakan Stok Harian', 'Laporan Stok', 'Laporan Transfer', 'Adjustment / Selisih', 'Expired / Near Expired', 'Pembelian per Supplier', 'Distribusi per Bakery', 'Slow / No Movement',
        'Rekonsiliasi Arus Stok', 'Audit Transaksi', 'Nilai Stok & HPP', 'Laporan Nilai Stok & HPP', 'Laporan P1/P2 Stock Opname', 'Laporan P1/P2 Stock Opname (lama)', 'Laporan Jejak Stock Opname'];
    return ['approved' => $approved, 'legacy' => $legacy, 'legacy_comment_block' => $legacyComment, 'old_report_tabs' => $oldReportTabs, 'old_report_labels' => $oldReportLabels,
        'opname_comment' => $oc[0] ?? '<!-- "Laporan Stock Opname" now lives in the Laporan menu. -->'];
}

/**
 * What a visitor sees in the sidebar: the report links outside the hidden container (by route or by label) and the report links kept hidden.
 * @return array{visible:list<string>,hidden:list<string>,old_visible:list<string>}
 */
function rv3_sidebar_report_links(string $html, array $op): array
{
    $masked = rv3_mask_comments($html);
    $nPos = preg_match('#<nav\b[^>]*\bid="sidebar"[^>]*>#', $masked, $nm, PREG_OFFSET_CAPTURE) ? $nm[0][1] : 0;
    $nEnd = ($e = strpos($masked, '</nav>', $nPos)) === false ? strlen($html) : $e;
    $cPos = strpos($masked, '<div class="sidebar-legacy-routes"');
    $c = $cPos === false ? null : rv3_div_span($html, $cPos);
    $approvedTabs = array_column($op['approved'], 'tab');
    $approvedLabels = array_map(static fn ($a) => rv3_anchor_label($a['markup']), $op['approved']);
    $oldTabs = $op['old_report_tabs'] ?? [];
    $oldLabels = array_map('mb_strtolower', $op['old_report_labels'] ?? []);
    $r = ['visible' => [], 'hidden' => [], 'old_visible' => []];
    foreach (rv3_anchor_spans($html) as $a) {
        if ($a['start'] < $nPos || $a['end'] > $nEnd) {
            continue;
        }
        $label = rv3_anchor_label($a['markup']);
        $isOld = in_array($a['tab'], $oldTabs, true) || in_array(mb_strtolower($label), $oldLabels, true);
        $isApproved = in_array($a['tab'], $approvedTabs, true) || in_array($label, $approvedLabels, true);
        if ($c !== null && $a['start'] >= $c[0] && $a['end'] <= $c[1]) {
            $r['hidden'][] = $a['tab'];
        } elseif ($isApproved) {
            $r['visible'][] = $label;
        } elseif ($isOld) {
            $r['old_visible'][] = $label;
        }
    }
    return $r;
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
    foreach ($payloadServices !== null ? rv3_glob(rtrim($payloadServices, '/') . '/*.php') : [] as $f) {
        $payload[basename($f)] = $f;
    }
    foreach (rv3_glob(rtrim($app, '/') . '/services/*.php') as $f) {
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

/** true when the host really has the function (shared hosting removes some: symlink, escapeshellarg, exec, disk_free_space …) */
function rv3_fn(string $name): bool
{
    return function_exists($name) && is_callable($name);
}

/** glob() with a scandir() fallback (single "*.ext" pattern in one directory) */
function rv3_glob(string $pattern): array
{
    if (rv3_fn('glob')) {
        return glob($pattern) ?: [];
    }
    $dir = dirname($pattern);
    $ext = substr(basename($pattern), 1);
    $out = [];
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $f) {
        if ($f !== '.' && $f !== '..' && str_ends_with($f, $ext)) {
            $out[] = $dir . '/' . $f;
        }
    }
    sort($out);
    return $out;
}

/** syntax check IN-PROCESS (token_get_all with TOKEN_PARSE throws ParseError exactly where `php -l` would fail): no child process, no shell. @return ?string the error, null when valid */
function rv3_lint(string $file): ?string
{
    $src = rv3_read($file);
    if ($src === null) {
        return 'cannot read the file';
    }
    try {
        token_get_all($src, TOKEN_PARSE);
    } catch (ParseError $e) {
        return $e->getMessage() . ' on line ' . $e->getLine();
    }
    return null;
}

/**
 * Loads the services IN-PROCESS (no child process, no copy, no link). Before requiring anything it checks that every file parses and that no class / function would be declared twice or
 * clashes with one already defined — so a problem is reported as a FAIL line instead of a fatal error. @param list<string> $files @return ?string the problem, null when loaded
 */
function rv3_load_services(array $files): ?string
{
    $seen = [];
    foreach ($files as $f) {
        if (($e = rv3_lint($f)) !== null) {
            return basename($f) . ': syntax error — ' . $e;
        }
        $src = (string) rv3_read($f);
        $ns = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $src, $m) ? $m[1] . '\\' : '';
        if (preg_match_all('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $src, $cm)) {
            foreach ($cm[1] as $c) {
                $fq = $ns . $c;
                if (isset($seen[strtolower($fq)]) || class_exists($fq, false) || interface_exists($fq, false)) {
                    return "{$fq} would be declared twice ({$f} and " . ($seen[strtolower($fq)] ?? 'an already loaded file') . ')';
                }
                $seen[strtolower($fq)] = basename($f);
            }
        }
    }
    try {
        foreach ($files as $f) {
            require_once $f;
        }
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
    return null;
}
