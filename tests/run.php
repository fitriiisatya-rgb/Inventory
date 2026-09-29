<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

// Started here, before any test output, so Auth::attemptLogin()'s
// session_regenerate_id() has a real session to work with in CLI mode
// (bootstrap.php only auto-starts one for PHP_SAPI !== 'cli').
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/TestRunner.php';
require __DIR__ . '/UnitConversionTest.php';
require __DIR__ . '/PermissionsTest.php';
require __DIR__ . '/AuthTest.php';
require __DIR__ . '/StockImportTest.php';

// Auth runs first: it triggers session_regenerate_id(), which sends a
// cookie header — harmless in a real request (login always precedes any
// output) but noisy here if other tests have already echoed to stdout.
test_auth();
test_unit_conversion();
test_permissions();
test_stock_import();

exit(T::summary());
