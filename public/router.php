<?php
/**
 * Router for PHP's built-in dev server only (php -S ... -t public router.php).
 * Not used in production (Apache uses .htaccess instead). Requests for a
 * real static file are served as-is; /api/* is dispatched to the REST
 * front controller; everything else falls back to the SPA shell.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    require __DIR__ . '/index.php';
    return true;
}

header('Content-Type: text/html; charset=UTF-8');
readfile(__DIR__ . '/index.html');
return true;
