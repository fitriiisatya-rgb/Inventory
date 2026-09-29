<?php
declare(strict_types=1);

// Single entry point every script (api/*, admin/*, bin/*) includes first.

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    die('config/config.php not found. Copy config/config.sample.php to config/config.php and fill in DB credentials.');
}
$GLOBALS['SO_CONFIG'] = require $configFile;

date_default_timezone_set($GLOBALS['SO_CONFIG']['app']['timezone'] ?? 'Asia/Jakarta');

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Permissions.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Validation.php';
require_once __DIR__ . '/UnitConversion.php';
require_once __DIR__ . '/SystemStockProvider/SystemStockProviderInterface.php';
require_once __DIR__ . '/SystemStockProvider/SystemStockResult.php';
require_once __DIR__ . '/SystemStockProvider/ImportSystemStockProvider.php';
require_once __DIR__ . '/StockImportService.php';
require_once __DIR__ . '/CodeNameCrud.php';
require_once __DIR__ . '/SessionService.php';
require_once __DIR__ . '/ItemLockService.php';
require_once __DIR__ . '/CountService.php';
require_once __DIR__ . '/ReconciliationService.php';

if (PHP_SAPI !== 'cli') {
    $sessionName = $GLOBALS['SO_CONFIG']['app']['session_name'] ?? 'so_session';
    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        // 'secure' left false here because local dev/testing runs over
        // plain HTTP; production deployment behind HTTPS must set true.
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}
