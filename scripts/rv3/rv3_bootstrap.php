<?php
declare(strict_types=1);

/**
 * ONE bootstrap for every reconciliation script of the package (so they cannot diverge again). It gives each script:
 *   - rv3_script_exit(): `exit` when the script runs on its own; when the package validator runs it IN-PROCESS (RV3_INPROCESS) the exit becomes an exception the validator catches
 *   - rv3_bootstrap_args(): the optional  --package-dir=<package>  /  --payload-services=<dir>  arguments, removed from $argv before the script parses its own
 *   - rv3_bootstrap_load(): loads the services — the INSTALLED ones, with the package's service files substituted in memory (same loader the validator and the self-check use), so a script
 *     works BEFORE the package is applied (e.g. MovementReportV3Service is not installed yet). In-process the validator has already loaded them, so this is a no-op.
 * No shell, no child process, no symlink, no copy, no temp file.
 */

require_once __DIR__ . '/rv3_lib.php';

if (!class_exists('RV3ScriptExit', false)) {
    final class RV3ScriptExit extends RuntimeException
    {
    }
}
if (!function_exists('rv3_script_exit')) {
    function rv3_script_exit(int $c): never
    {
        if (defined('RV3_INPROCESS')) {
            throw new RV3ScriptExit('exit', $c);
        }
        exit($c);
    }
}
if (!function_exists('rv3_bootstrap_args')) {
    /** @param array<int,string> $argv by reference. @return ?string the payload services directory, or null */
    function rv3_bootstrap_args(array &$argv): ?string
    {
        $payload = null;
        foreach ($argv as $i => $a) {
            if ($i > 0 && str_starts_with($a, '--package-dir=')) {
                $payload = rtrim(substr($a, 14), '/') . '/payload/services';
                unset($argv[$i]);
            } elseif ($i > 0 && str_starts_with($a, '--payload-services=')) {
                $payload = rtrim(substr($a, 19), '/');
                unset($argv[$i]);
            }
        }
        $argv = array_values($argv);
        return $payload !== null && is_dir($payload) ? $payload : null;
    }
}
if (!function_exists('rv3_bootstrap_load')) {
    /** @param list<string> $needClasses classes the script cannot run without (fails with a clear message instead of a fatal error) */
    function rv3_bootstrap_load(string $appRoot, ?string $payloadServices, array $needClasses = []): void
    {
        if (!defined('RV3_INPROCESS')) {
            $err = rv3_load_services(rv3_service_files($appRoot, $payloadServices));
            if ($err !== null) {
                fwrite(STDERR, "ABORT: could not load the services: {$err}\n");
                rv3_script_exit(3);
            }
        }
        foreach ($needClasses as $c) {
            if (!class_exists($c)) {
                fwrite(STDERR, "ABORT: {$c} is not installed in {$appRoot}/services — run with  --package-dir=<package folder>  to validate with the package's code before it is applied.\n");
                rv3_script_exit(2);
            }
        }
    }
}
