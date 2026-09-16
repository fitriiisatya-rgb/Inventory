<?php
declare(strict_types=1);

/**
 * Front controller for the internal REST API (Section 24). Document root
 * for the API vhost/subpath must point here; every request is routed
 * through this single script so response shape, CORS, auth and error
 * handling are enforced in exactly one place.
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/FifoService.php';

use App\Services\AuthService;
use App\Services\Database;
use App\Services\InsufficientStockException;
use App\Services\PriceAnomalyException;
use App\Services\ValidationException;
use App\Services\FifoService;

$config = require __DIR__ . '/../config/config.php';

// ---- error handling: never leak stack traces to a production client (Section: "error PHP tidak ditampilkan") ----
ini_set('display_errors', $config['app']['debug'] ? '1' : '0');
error_reporting(E_ALL);
if (!is_dir(dirname($config['log']['path']))) {
    @mkdir(dirname($config['log']['path']), 0755, true);
}
ini_set('log_errors', '1');
ini_set('error_log', $config['log']['path']);

function inv_respond(int $httpStatus, bool $success, mixed $data, string $message): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- CORS: only the configured new-domain origins, never a silent open policy ----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $config['cors']['allowed_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

AuthService::bootSession($config['session']);

set_exception_handler(function (Throwable $e) {
    error_log('[unhandled] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $map = [
        InsufficientStockException::class => ['code' => 422, 'label' => 'STOCK_INSUFFICIENT'],
        PriceAnomalyException::class       => ['code' => 422, 'label' => 'PRICE_ANOMALY'],
        ValidationException::class         => ['code' => 422, 'label' => 'VALIDATION_FAILED'],
    ];
    foreach ($map as $class => $info) {
        if ($e instanceof $class) {
            inv_respond($info['code'], false, ['error_code' => $info['label']], $e->getMessage());
        }
    }
    inv_respond(500, false, null, 'Internal server error');
});

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = preg_replace('#^/api#', '', rtrim($path, '/'));
$path = $path === '' ? '/' : $path;

$rawBody = file_get_contents('php://input') ?: '';
$input = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $input = $decoded;
    }
}
$query = $_GET;

function inv_require_auth(): array
{
    $user = AuthService::currentUser();
    if ($user === null) {
        inv_respond(401, false, null, 'Not authenticated');
    }
    return $user;
}

function inv_require_permission(PDO $pdo, array $user, string $permission): void
{
    if (!AuthService::hasPermission($pdo, $user['role_code'], $permission)) {
        inv_respond(403, false, null, "Missing permission: {$permission}");
    }
}

$pdo = Database::connection();

// ---- routing table: [METHOD, path] => handler ----
$routes = [
    'POST /auth/login' => function () use ($pdo, $input) {
        $user = AuthService::attemptLogin($pdo, (string) ($input['username'] ?? ''), (string) ($input['password'] ?? ''));
        if ($user === null) {
            inv_respond(401, false, null, 'Invalid credentials');
        }
        inv_respond(200, true, ['username' => $user['username'], 'role' => $user['role_code']], 'Logged in');
    },
    'POST /auth/logout' => function () {
        AuthService::logout();
        inv_respond(200, true, null, 'Logged out');
    },
    'GET /auth/me' => function () {
        inv_respond(200, true, AuthService::currentUser(), 'OK');
    },

    'GET /items' => function () use ($pdo) {
        inv_require_auth();
        $rows = $pdo->query('SELECT * FROM items ORDER BY name')->fetchAll();
        inv_respond(200, true, $rows, 'OK');
    },

    'GET /warehouses' => function () use ($pdo) {
        inv_require_auth();
        inv_respond(200, true, $pdo->query('SELECT * FROM warehouses ORDER BY name')->fetchAll(), 'OK');
    },
    'GET /suppliers' => function () use ($pdo) {
        inv_require_auth();
        inv_respond(200, true, $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll(), 'OK');
    },
    'GET /divisions' => function () use ($pdo) {
        inv_require_auth();
        inv_respond(200, true, $pdo->query('SELECT * FROM divisions ORDER BY name')->fetchAll(), 'OK');
    },

    'GET /inventory/current' => function () use ($pdo, $query) {
        inv_require_auth();
        $stock = FifoService::currentStock($pdo, (int) $query['item_id'], (int) $query['warehouse_id']);
        inv_respond(200, true, $stock, 'OK');
    },
    'GET /inventory/batches' => function () use ($pdo, $query) {
        inv_require_auth();
        $stmt = $pdo->prepare(
            'SELECT * FROM inventory_batches WHERE item_id = :item_id AND warehouse_id = :wh AND qty_base <> 0
             ORDER BY received_date ASC, id ASC'
        );
        $stmt->execute(['item_id' => (int) $query['item_id'], 'wh' => (int) $query['warehouse_id']]);
        inv_respond(200, true, $stmt->fetchAll(), 'OK');
    },

    'POST /transactions/in' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_IN_CREATE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, $input));
        inv_respond(200, true, $result, 'Transaction posted');
    },
    'POST /transactions/out' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        if (!empty($input['allow_negative_stock'])) {
            inv_require_permission($pdo, $user, 'STOCK_ALLOW_NEGATIVE');
        }
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, $input));
        inv_respond(200, true, $result, 'Transaction posted');
    },
];

$key = "{$method} {$path}";
if (isset($routes[$key])) {
    $routes[$key]();
} else {
    inv_respond(404, false, null, "No route for {$key}");
}
