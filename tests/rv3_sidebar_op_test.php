<?php
declare(strict_types=1);

/**
 * Unit test of the html_sidebar op (scripts/rv3/rv3_lib.php) — "the Laporan menu shows exactly the five approved reports, whatever the production markup was".
 * Inputs: the committed dev index.html, EVERY historical version of public/index.html that has a Laporan group (git history = the layouts production may have run),
 * and hostile synthetic variants (old links in a second group, a duplicated approved link, a comment quoting </div> and an old link, no hidden container, a label-only old link).
 * For each input the op output must satisfy: (1) exactly the five approved report links outside the hidden container, in order, with the exact labels; (2) no old report
 * link outside the hidden container (by route or by label); (3) every old route is still present in the hidden container (route compatibility); (4) the links of other groups
 * (Mutasi Stok / Ledger, Laporan Distribusi, Audit Log …) are untouched; (5) idempotent: running the op on its own output changes nothing; (6) div balance preserved.
 *   php tests/rv3_sidebar_op_test.php
 */
$root = dirname(__DIR__);
require_once $root . '/scripts/rv3/rv3_lib.php';

$pass = 0;
$fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo ($cond ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' && !$cond ? " ({$detail})" : '') . "\n";
}

$dev = (string) file_get_contents($root . '/public/index.html');
$op = ['id' => 'x', 'type' => 'html_sidebar', 'target' => 'public/index.html'] + rv3_sidebar_op_from_html($dev);
$approved = [['laporan-pergerakan', 'Laporan Pergerakan Stok'], ['laporan-inout', 'Laporan IN / OUT'], ['laporan-pembelian', 'Laporan Pembelian'], ['laporan-hpp', 'Laporan Nilai HPP'], ['laporan-opname', 'Laporan Stock Opname']];
$oldTabs = $op['old_report_tabs'];
$oldLabels = array_map('mb_strtolower', $op['old_report_labels']);

/** @return array{visible:list<array{tab:string,label:string}>,hidden:list<string>,all:list<string>} */
function scan(string $html): array
{
    $masked = rv3_mask_comments($html);
    $nPos = preg_match('#<nav\b[^>]*\bid="sidebar"[^>]*>#', $masked, $nm, PREG_OFFSET_CAPTURE) ? $nm[0][1] : 0;
    $nEnd = strpos($masked, '</nav>', $nPos) ?: strlen($html);
    $cPos = strpos($masked, '<div class="sidebar-legacy-routes"');
    $c = $cPos === false ? null : rv3_div_span($html, $cPos);
    $vis = [];
    $hid = [];
    $all = [];
    foreach (rv3_anchor_spans($html) as $a) {
        if ($a['start'] < $nPos || $a['end'] > $nEnd) {
            continue;
        }
        $all[] = $a['tab'];
        if ($c !== null && $a['start'] >= $c[0] && $a['end'] <= $c[1]) {
            $hid[] = $a['tab'];
        } else {
            $vis[] = ['tab' => $a['tab'], 'label' => rv3_anchor_label($a['markup'])];
        }
    }
    return ['visible' => $vis, 'hidden' => $hid, 'all' => $all];
}

function check_variant(string $label, string $html): void
{
    global $op, $approved, $oldTabs, $oldLabels;
    $before = scan($html);
    [$out, $state] = rv3_op_html_sidebar($html, $op, '');
    ok("{$label}: op result is todo/done (not conflict)", in_array($state, ['todo', 'done'], true), (string) $state);
    if (!in_array($state, ['todo', 'done'], true)) {
        return;
    }
    $a = scan($out);
    $reports = array_values(array_filter($a['visible'], static fn ($l) => in_array($l['tab'], array_merge($oldTabs, array_column($approved, 0)), true) || in_array(mb_strtolower($l['label']), $oldLabels, true) || in_array($l['label'], array_column($approved, 1), true)));
    ok("{$label}: exactly 5 visible report links, exact labels + order", array_map(static fn ($l) => [$l['tab'], $l['label']], $reports) === $approved, json_encode($reports));
    $stray = array_filter($a['visible'], static fn ($l) => in_array($l['tab'], $oldTabs, true) || in_array(mb_strtolower($l['label']), $oldLabels, true));
    ok("{$label}: no old report link visible", count($stray) === 0, json_encode(array_values($stray)));
    $missing = array_diff(array_intersect($before['all'], $oldTabs), $a['hidden']);
    ok("{$label}: every old route that was present is kept in the hidden container", count($missing) === 0, implode(',', $missing));
    foreach (['laporan', 'distribusi-laporan', 'audit', 'stok-barang', 'opname'] as $keep) {
        if (in_array($keep, $before['all'], true)) {
            ok("{$label}: unrelated link {$keep} untouched (still visible)", count(array_filter($a['visible'], static fn ($l) => $l['tab'] === $keep)) === 1);
        }
    }
    [$again, $st2] = rv3_op_html_sidebar($out, $op, '');
    ok("{$label}: idempotent (second pass = done, identical)", $st2 === 'done' && $again === $out, (string) $st2);
    $masked = rv3_mask_comments($out);
    ok("{$label}: <div> balance preserved", substr_count($masked, '<div') === substr_count($masked, '</div>') + (substr_count(rv3_mask_comments($html), '<div') - substr_count(rv3_mask_comments($html), '</div>')));
}

// 1. the committed dev index.html
check_variant('dev index.html', $dev);

// 2. every historical index.html that has a Laporan group
$revs = array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($root) . ' && git log --format=%h -- public/index.html 2>/dev/null')));
$seenHash = [];
$tested = 0;
foreach ($revs as $h) {
    $html = (string) shell_exec('cd ' . escapeshellarg($root) . ' && git show ' . escapeshellarg($h . ':public/index.html') . ' 2>/dev/null');
    if (!preg_match('#data-group="laporan"#', $html)) {
        continue;
    }
    $sig = md5(json_encode(scan($html)['visible']));
    if (isset($seenHash[$sig])) {
        continue;                                                  // identical sidebar layout already covered
    }
    $seenHash[$sig] = true;
    $tested++;
    check_variant("history {$h}", $html);
}
ok('at least 3 distinct historical sidebar layouts were exercised', $tested >= 3, (string) $tested);

// 3. hostile synthetic variants of the dev markup
$link = static fn (string $tab, string $label, string $icon = '📄'): string => '<a class="sidebar-link" data-tab="' . $tab . '" data-require-permission="INVENTORY_VIEW"><span class="icon">' . $icon . '</span> ' . $label . '</a>';
$variants = [];

$v = $dev;
$v = preg_replace('#(<div class="sidebar-group sidebar-group-collapsible" data-group="laporan">)#', "<!-- old: </div> " . $link('laporan-stok', 'Laporan Stok') . " -->\n            $1", $v, 1);
$variants['comment quoting </div> and an old link'] = $v;

$v = $dev;
$extraGroup = "<div class=\"sidebar-group sidebar-group-collapsible\" data-group=\"reports\"><button type=\"button\" class=\"sidebar-group-header\"><span class=\"sidebar-group-label\">Reports</span></button><div class=\"sidebar-submenu\" hidden>\n"
    . $link('laporan-ringkasan', 'Ringkasan Inventory') . $link('laporan-xyz', 'Pergerakan Stok Harian') . $link('laporan-stok', 'Laporan Stok') . $link('laporan-pembelian', 'Laporan Pembelian') . $link('laporan-audit', 'Audit Transaksi')
    . "\n</div></div>\n";
$v = str_replace('<div class="sidebar-group sidebar-group-collapsible" data-group="inventory">', $extraGroup . '            <div class="sidebar-group sidebar-group-collapsible" data-group="inventory">', $v);
$variants['old links + a duplicated approved link in a second Reports group, one label-only (unknown route)'] = $v;

$v = str_replace($link('stok-barang', 'Stok Barang'), $link('stok-barang', 'Stok Barang') . $link('laporan-hpp', 'Nilai Stok & HPP'), $dev);
if ($v === $dev) {
    $v = preg_replace('#(data-group="inventory">.*?<div class="sidebar-submenu"[^>]*>)#s', '$1' . $link('laporan-hpp', 'Nilai Stok & HPP') . $link('laporan-supplier', 'Pembelian per Supplier'), $dev, 1);
}
$variants['approved + old link inside the Inventory group'] = $v;

$v = preg_replace('#(<a class="sidebar-link" data-tab="laporan-inout".*?</a>)#s', '$1' . "\n" . $link('laporan-inout', 'Laporan IN / OUT') . "\n" . $link('laporan-transfer', 'Laporan Transfer'), $dev, 1);
$variants['duplicate approved row + Laporan Transfer inside the Laporan submenu'] = $v;

$cPos = strpos($dev, '<div class="sidebar-legacy-routes"');
$cEnd = rv3_div_span($dev, $cPos)[1];
$variants['no hidden container at all'] = substr($dev, 0, $cPos) . substr($dev, $cEnd);

// the whole old production menu: the 11 old links + the five new, flat in the Laporan submenu (the layout the user reported)
$gp = strpos($dev, 'data-group="laporan"');
$sp = strpos($dev, '<div class="sidebar-submenu"', $gp);
$sSpan = rv3_div_span($dev, $sp);
$old11 = $link('laporan-ringkasan', 'Ringkasan Inventory') . "\n" . $link('laporan-pergerakan', 'Pergerakan Stok Harian') . "\n" . $link('laporan-stok', 'Laporan Stok') . "\n" . $link('laporan-transfer', 'Laporan Transfer') . "\n"
    . $link('laporan-adjustment', 'Adjustment / Selisih') . "\n" . $link('laporan-expiry', 'Expired / Near Expired') . "\n" . $link('laporan-supplier', 'Pembelian per Supplier') . "\n" . $link('laporan-bakery', 'Distribusi per Bakery') . "\n"
    . $link('laporan-slow-movement', 'Slow / No Movement') . "\n" . $link('laporan-rekonsiliasi', 'Rekonsiliasi Arus Stok') . "\n" . $link('laporan-audit', 'Audit Transaksi');
$subOpen = preg_match('#<div class="sidebar-submenu"[^>]*>#', $dev, $mm, 0, $sp) ? $mm[0] : '';
$variants['the reported production menu: 11 old links flat in the Laporan submenu'] = substr($dev, 0, $sp) . $subOpen . "\n" . $old11 . "\n" . str_repeat(' ', 16) . '</div>' . substr($dev, $sSpan[1]);

foreach ($variants as $name => $html) {
    check_variant($name, $html);
}

echo "\n{$pass} / " . ($pass + $fail) . " PASSED\n";
exit($fail === 0 ? 0 : 1);
