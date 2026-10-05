<?php
declare(strict_types=1);

/**
 * READ-ONLY state detector for the Laporan Stock Opname V3 package. Never writes. It classifies the production tree so the right path is taken, fail-closed:
 *
 *   CLEAN             nothing of the report is installed                               → PATH A (everything is new)
 *   PARTIAL_V2_BACKEND  the SOA V2 BACKEND is applied (service = the exact V2 file, index.php carries the V2 require / helpers / routes, V2 state files present) and the
 *                     frontend is NOT (no stock-opname-report.js, no .soa-* css, app.js still routes to ReportOpname, no soa token in index.html)  → PATH B (upgrade)
 *   V3_APPLIED        this package is already applied (state files .soa3-patch.json)                                                 → nothing to do
 *   anything else     (V2 frontend present, service edited, half-applied index.php, …) → REFUSED with the reasons; exit code 1; nothing changes
 *
 *   php scripts/soa3_state_check.php --public-dir=<public/> --services-dir=<services/> [--payload-dir=<package payload/>]
 */

$public = $services = $payload = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--public-dir=')) { $public = rtrim(substr($arg, 13), '/'); }
    elseif (str_starts_with($arg, '--services-dir=')) { $services = rtrim(substr($arg, 15), '/'); }
    elseif (str_starts_with($arg, '--payload-dir=')) { $payload = rtrim(substr($arg, 14), '/'); }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); exit(2); }
}
if ($public === null || $services === null || !is_dir($public) || !is_dir($services)) {
    fwrite(STDERR, "usage: php scripts/soa3_state_check.php --public-dir=<public/> --services-dir=<services/> [--payload-dir=<payload/>]\n");
    exit(2);
}
$payload ??= dirname(__DIR__) . '/payload';

// SHA256 of the files the SOA V2 package installed (package SHA256 ce93de3c…): the exact V2 service, helper payload and routes payload
const V2_SERVICE_SHA = '8d5e45091ea0a13b5212918ec13871986de729701466245ba76982fcbdec74eb';
const V2_HELPER_SHA = 'b75c744af5b6fdd04a577032b40642e06bada9f0183db123b75338fb6bc9de31';
const V2_ROUTES_SHA = '279539cbab11775ac459f81b0aa68c264223febd3f5a85de674b65fd82214cc0';

$read = static fn (string $p): ?string => is_file($p) ? (string) file_get_contents($p) : null;
$sha = static fn (?string $c): ?string => $c === null ? null : hash('sha256', $c);
$svcPath = "{$services}/StockOpnameAuditReportService.php";
$svc = $read($svcPath);
$php = (string) $read("{$public}/index.php");
$html = (string) $read("{$public}/index.html");
$css = (string) $read("{$public}/assets/css/app.css");
$app = (string) $read("{$public}/assets/js/app.js");
$js = $read("{$public}/assets/js/stock-opname-report.js");
$helper = $read("{$payload}/soa_index_php_helper.txt");
$routes = $read("{$payload}/soa_index_php_routes.txt");
$v3Svc = $sha($read("{$payload}/StockOpnameAuditReportService.php"));

$reasons = [];
$facts = [];
// ---- backend
$svcState = $svc === null ? 'ABSENT' : ($sha($svc) === V2_SERVICE_SHA ? 'V2' : ($sha($svc) === $v3Svc ? 'V3' : 'OTHER'));
$facts[] = "service StockOpnameAuditReportService.php: {$svcState}" . ($svc !== null ? ' (sha256 ' . $sha($svc) . ')' : '');
$req = substr_count($php, "require_once __DIR__ . '/../services/StockOpnameAuditReportService.php';");
$hCount = $helper !== null ? substr_count($php, $helper) : -1;
$rCount = $routes !== null ? substr_count($php, $routes) : -1;
$helperIsV2 = $helper !== null && hash('sha256', $helper) === V2_HELPER_SHA;
$routesIsV2 = $routes !== null && hash('sha256', $routes) === V2_ROUTES_SHA;
$anyIdx = $req + substr_count($php, 'inv_soa_filters') + substr_count($php, "'GET /reports/opname-audit/");
$idxState = $anyIdx === 0 ? 'CLEAN' : (($req === 1 && $hCount === 1 && $rCount === 1) ? 'V2_BACKEND' : 'PARTIAL_OR_DIFFERENT');
$facts[] = "index.php: {$idxState} (require x{$req}, helpers x{$hCount}, routes x{$rCount}; payloads identical to V2: " . ($helperIsV2 && $routesIsV2 ? 'yes' : 'NO') . ')';
$v2State = [is_file("{$services}/StockOpnameAuditReportService.php.soa-patch.json"), is_file("{$public}/index.php.soa-patch.json")];
$facts[] = 'SOA V2 state files (.soa-patch.json): service ' . ($v2State[0] ? 'present' : 'absent') . ', index.php ' . ($v2State[1] ? 'present' : 'absent') . ' (never touched by V3)';
// ---- frontend
$feJs = $js !== null && str_contains($js, 'soa-sessions-table');          // the SOA report page (V2 / V3)
$feJsLegacy = $js !== null && !$feJs;                                        // an older stock-opname-report.js (the V2.16.4 monthly report): replaced behind a hash gate
$feCss = substr_count($css, '/* Laporan Stock Opname audit redesign (stock-opname-report.js') + substr_count($css, '.soa-');
$feApp = substr_count($app, "StockOpnameReport.render(document.getElementById('tab-laporan-opname'))");
$feOldRoute = substr_count($app, "ReportOpname.render(document.getElementById('tab-laporan-opname'));");
$feHtml = (int) preg_match_all('/\?v=[A-Za-z0-9._-]*soa[A-Za-z0-9._-]*"/', $html);
$facts[] = 'frontend: stock-opname-report.js ' . ($feJs ? 'PRESENT (SOA report)' : ($feJsLegacy ? 'present (an OLDER report page — replaced behind a hash gate: --replace-expect-sha256=' . $sha($js) . ')' : 'absent')) . ', .soa- css x' . $feCss . ', app.js re-pointed x' . $feApp . ' (old route line x' . $feOldRoute . '), index.html "soa" tokens x' . $feHtml;
$facts[] = 'Jejak drawer tag stock-opname-report-jejak.js in index.html: x' . preg_match_all('#assets/js/stock-opname-report-jejak\.js\?v=#', $html);
$v3Files = [is_file("{$services}/StockOpnameAuditReportService.php.soa3-patch.json"), is_file("{$public}/index.php.soa3-patch.json"), is_file("{$public}/assets/js/app.js.soa3-patch.json")];

$state = 'UNKNOWN';
if (in_array(true, $v3Files, true)) {
    $state = 'V3_APPLIED';
    $reasons[] = 'V3 state files (.soa3-patch.json) exist — this package is (partly) applied already; use rollback_soa3_production.php or send the output';
} elseif ($svcState === 'ABSENT' && $idxState === 'CLEAN' && !$feJs && $feCss === 0 && $feApp === 0 && $feHtml === 0) {
    $state = 'CLEAN';
} elseif ($svcState === 'V2' && $idxState === 'V2_BACKEND' && $helperIsV2 && $routesIsV2 && !$feJs && $feCss === 0 && $feApp === 0 && $feOldRoute === 1 && $feHtml === 0) {
    $state = 'PARTIAL_V2_BACKEND';
    if (!($v2State[0] && $v2State[1])) { $reasons[] = 'note: the V2 state files are not both present (informational — V3 does not use them)'; }
} else {
    if ($svcState === 'OTHER') { $reasons[] = 'the service file is neither the V2 file nor the V3 file (edited locally, or another version) — refusing to overwrite it'; }
    if ($idxState === 'PARTIAL_OR_DIFFERENT') { $reasons[] = 'index.php carries only part of the report backend or a different version'; }
    if ($idxState === 'V2_BACKEND' && !($helperIsV2 && $routesIsV2)) { $reasons[] = 'index.php has the report backend but this package\'s helper / routes payloads are NOT identical to V2\'s — an index.php upgrade is not supported'; }
    if ($feJs || $feCss > 0 || $feApp > 0 || $feHtml > 0) { $reasons[] = 'a frontend of the report is already (partly) installed (stock-opname-report.js / .soa- css / app.js route / soa token) — V3 only upgrades the "V2 backend, no frontend" state; roll that frontend back first'; }
    if ($svcState === 'ABSENT' && $idxState !== 'CLEAN') { $reasons[] = 'index.php has report pieces but the service file is missing'; }
    if ($svcState === 'V2' && $feOldRoute !== 1) { $reasons[] = 'app.js does not contain the expected ReportOpname route line exactly once'; }
    if ($reasons === []) { $reasons[] = 'the tree does not match any supported state'; }
}
echo "== SOA V3 — production state ==\n";
foreach ($facts as $f) { echo "   {$f}\n"; }
echo "\nSTATE: {$state}\n";
foreach ($reasons as $r) { echo "   - {$r}\n"; }
if ($state === 'CLEAN') {
    echo "\nPATH A (clean): install the service as a NEW file, patch index.php (inserts the backend), verify the backend, then the frontend.\n";
} elseif ($state === 'PARTIAL_V2_BACKEND') {
    echo "\nPATH B (upgrade from the SOA V2 backend): REPLACE the service with the V3 file, behind this gate:\n";
    echo '   --replace-expect-sha256=' . $sha($svc) . "   (the exact V2 service file)\n";
    echo "   index.php is verified, NOT modified; then verify the backend, then install the frontend.\n";
}
exit(in_array($state, ['CLEAN', 'PARTIAL_V2_BACKEND'], true) ? 0 : 1);
