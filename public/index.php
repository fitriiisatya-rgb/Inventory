<?php
declare(strict_types=1);

/**
 * Front controller for the internal REST API (Section 24 / PHASE D0.3
 * frozen contract — see docs/API_CONTRACT.md). Document root for the API
 * vhost/subpath must point here; every request is routed through this
 * single script so response shape, CORS, auth, CSRF and error handling are
 * enforced in exactly one place.
 *
 * Response shape (frozen — do not change without updating API_CONTRACT.md):
 *   success: {"success":true,"data":{...},"message":"..."}
 *   error:   {"success":false,"error":{"code":"...","message":"..."}}
 * The frontend must branch on error.code, never on error.message text.
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/ProductionService.php';
require_once __DIR__ . '/../services/BookClosingService.php';
require_once __DIR__ . '/../services/ReconciliationService.php';
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/ImportMasterItemService.php';
require_once __DIR__ . '/../services/ImportSimpleMasterService.php';
require_once __DIR__ . '/../services/ImportOpeningStockService.php';
require_once __DIR__ . '/../services/ImportHistoricalTransactionService.php';

use App\Services\AuthService;
use App\Services\Database;
use App\Services\InsufficientStockException;
use App\Services\NegativeStockException;
use App\Services\PriceAnomalyException;
use App\Services\PeriodLockedException;
use App\Services\WarehouseLockedException;
use App\Services\CostRequiredException;
use App\Services\RateLimitedException;
use App\Services\NotFoundException;
use App\Services\TransferAlreadyReceivedException;
use App\Services\TransferAlreadyCancelledException;
use App\Services\DuplicateRequestException;
use App\Services\ImportValidationException;
use App\Services\ValidationException;
use App\Services\FifoService;
use App\Services\InventoryService;
use App\Services\TransferService;
use App\Services\StockOpnameService;
use App\Services\StockAdjustmentService;
use App\Services\ProductionService;
use App\Services\BookClosingService;
use App\Services\ReconciliationService;
use App\Services\VoidService;
use App\Services\ImportMasterItemService;
use App\Services\ImportSimpleMasterService;
use App\Services\ImportOpeningStockService;
use App\Services\ImportHistoricalTransactionService;

$config = require __DIR__ . '/../config/config.php';

// ---- error handling: never leak stack traces to a production client (Section: "error PHP tidak ditampilkan") ----
ini_set('display_errors', $config['app']['debug'] ? '1' : '0');
error_reporting(E_ALL);
if (!is_dir(dirname($config['log']['path']))) {
    @mkdir(dirname($config['log']['path']), 0755, true);
}
ini_set('log_errors', '1');
ini_set('error_log', $config['log']['path']);

/** Success envelope — frozen shape, see file docblock. */
function inv_ok(mixed $data, string $message = 'OK', int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Error envelope — frozen shape, see file docblock. Frontend branches on `code`, never on `message`. */
function inv_error(int $httpStatus, string $code, string $message): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- CORS: only the configured new-domain origins, never a silent open policy ----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $config['cors']['allowed_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

AuthService::bootSession($config['session']);

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

set_exception_handler(function (Throwable $e) use ($path) {
    // A genuine unique-key race (two concurrent requests with the identical
    // idempotency key, both past the "already exists?" pre-check before
    // either committed) surfaces here as a raw PDO duplicate-entry error —
    // translate it instead of leaking a 500.
    if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) {
        $e = new DuplicateRequestException();
    }

    error_log('[unhandled] ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    $importPrefixed = str_starts_with($path, '/import/');
    $map = [
        InsufficientStockException::class        => ['code' => 422, 'label' => 'INSUFFICIENT_STOCK'],
        NegativeStockException::class             => ['code' => 422, 'label' => 'NEGATIVE_STOCK'],
        PriceAnomalyException::class              => ['code' => 422, 'label' => 'PRICE_ANOMALY'],
        PeriodLockedException::class              => ['code' => 423, 'label' => 'PERIOD_LOCKED'],
        WarehouseLockedException::class           => ['code' => 423, 'label' => 'OPNAME_ACTIVE'],
        CostRequiredException::class              => ['code' => 422, 'label' => 'COST_REQUIRED'],
        RateLimitedException::class               => ['code' => 429, 'label' => 'RATE_LIMITED'],
        NotFoundException::class                  => ['code' => 404, 'label' => 'NOT_FOUND'],
        TransferAlreadyReceivedException::class   => ['code' => 409, 'label' => 'TRANSFER_ALREADY_RECEIVED'],
        TransferAlreadyCancelledException::class  => ['code' => 409, 'label' => 'TRANSFER_ALREADY_CANCELLED'],
        DuplicateRequestException::class          => ['code' => 409, 'label' => 'DUPLICATE_REQUEST'],
        ImportValidationException::class          => ['code' => 422, 'label' => 'IMPORT_VALIDATION_FAILED'],
        ValidationException::class                => ['code' => 422, 'label' => $importPrefixed ? 'IMPORT_VALIDATION_FAILED' : 'VALIDATION_ERROR'],
    ];
    foreach ($map as $class => $info) {
        if ($e instanceof $class) {
            inv_error($info['code'], $info['label'], $e->getMessage());
        }
    }
    inv_error(500, 'INTERNAL_ERROR', 'Internal server error');
});

// ---- CSRF (Section: cookie session -> CSRF protection) ----
// Every mutating request from an authenticated session must carry the
// synchronizer token issued at login. Login itself is exempt (no session
// yet); GET/HEAD/OPTIONS are exempt (must stay side-effect-free).
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && $path !== '/auth/login') {
    if (!empty($_SESSION['user_id'])) {
        $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!AuthService::verifyCsrf($csrfHeader)) {
            inv_error(403, 'CSRF_INVALID', 'Missing or invalid X-CSRF-Token header');
        }
    }
}

function inv_require_auth(): array
{
    $user = AuthService::currentUser();
    if ($user === null) {
        inv_error(401, 'UNAUTHENTICATED', 'Not authenticated');
    }
    return $user;
}

function inv_require_permission(PDO $pdo, array $user, string $permission): void
{
    if (!AuthService::hasPermission($pdo, $user['role_code'], $permission)) {
        inv_error(403, 'FORBIDDEN', "Missing permission: {$permission}");
    }
}

function inv_require_warehouse_scope(array $user, int $warehouseId): void
{
    try {
        AuthService::assertWarehouseScope($user, $warehouseId);
    } catch (ValidationException $e) {
        inv_error(403, 'FORBIDDEN', $e->getMessage());
    }
}

function inv_require_division_scope(array $user, ?int $divisionId): void
{
    try {
        AuthService::assertDivisionScope($user, $divisionId);
    } catch (ValidationException $e) {
        inv_error(403, 'FORBIDDEN', $e->getMessage());
    }
}

$pdo = Database::connection();

// ---- routing table ----
// Static routes are matched by exact "METHOD /path" key; parameterized
// routes are declared with {name} placeholders and matched by regex.
$routes = [
    'POST /auth/login' => function () use ($pdo, $input) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user = AuthService::attemptLogin($pdo, (string) ($input['username'] ?? ''), (string) ($input['password'] ?? ''), $ip);
        if ($user === null) {
            inv_error(401, 'UNAUTHENTICATED', 'Invalid credentials');
        }
        inv_ok(['username' => $user['username'], 'role' => $user['role_code'], 'csrf_token' => AuthService::csrfToken()], 'Logged in');
    },
    'POST /auth/logout' => function () {
        AuthService::logout();
        inv_ok(null, 'Logged out');
    },
    'GET /auth/me' => function () {
        $user = AuthService::currentUser();
        if ($user) {
            $user['csrf_token'] = AuthService::csrfToken();
        }
        inv_ok($user, 'OK');
    },

    'GET /items' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT * FROM items ORDER BY name')->fetchAll(), 'OK');
    },
    'GET /warehouses' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT * FROM warehouses ORDER BY name')->fetchAll(), 'OK');
    },
    'GET /suppliers' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll(), 'OK');
    },
    'GET /divisions' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT * FROM divisions ORDER BY name')->fetchAll(), 'OK');
    },

    // ---- InventoryService: single source of truth reads (Section 7) ----
    'GET /inventory/current' => function () use ($pdo, $query) {
        inv_require_auth();
        inv_ok(InventoryService::currentStock($pdo, (int) $query['item_id'], (int) $query['warehouse_id']), 'OK');
    },
    'GET /inventory/current/{sku}' => function (array $params) use ($pdo, $query) {
        inv_require_auth();
        $item = $pdo->prepare('SELECT id FROM items WHERE sku = :sku');
        $item->execute(['sku' => $params['sku']]);
        $itemId = $item->fetchColumn();
        if ($itemId === false) {
            inv_error(404, 'NOT_FOUND', 'SKU not found');
        }
        if (isset($query['warehouse_id'])) {
            inv_ok(InventoryService::currentStock($pdo, (int) $itemId, (int) $query['warehouse_id']), 'OK');
        }
        inv_ok(InventoryService::currentStockAllWarehouses($pdo, (int) $itemId), 'OK');
    },
    'GET /inventory/batches' => function () use ($pdo, $query) {
        inv_require_auth();
        inv_ok(InventoryService::batches($pdo, (int) $query['item_id'], (int) $query['warehouse_id']), 'OK');
    },
    'GET /inventory/value' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(InventoryService::companyTotalValue($pdo), 'OK');
    },
    'GET /inventory/in-transit' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(['in_transit_value' => InventoryService::inTransitValue($pdo)], 'OK');
    },
    'GET /inventory/ledger' => function () use ($pdo, $query) {
        inv_require_auth();
        inv_ok(InventoryService::ledger($pdo, (int) $query['item_id'], (int) $query['warehouse_id']), 'OK');
    },

    'POST /transactions/in' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_IN_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, $input));
        inv_ok($result, 'Transaction posted');
    },
    'POST /transactions/out' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        inv_require_division_scope($user, isset($input['division_id']) ? (int) $input['division_id'] : null);
        if (!empty($input['allow_negative_stock'])) {
            inv_require_permission($pdo, $user, 'STOCK_ALLOW_NEGATIVE');
        }
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, $input));
        inv_ok($result, 'Transaction posted');
    },
    'POST /transactions/{id}/void' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_VOID');
        if (!empty($input['superadmin_override'])) {
            inv_require_permission($pdo, $user, 'TRANSACTION_VOID_LOCKED_PERIOD');
        }
        $input['transaction_id'] = (int) $params['id'];
        $input['voided_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => VoidService::void($tx, $input));
        inv_ok($result, 'Transaction voided');
    },

    // ---- Transfers (PHASE C2 Section 1) ----
    'POST /transfers' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');
        if (isset($input['from_warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['from_warehouse_id']); }
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => TransferService::create($tx, $input));
        inv_ok($result, 'Transfer created');
    },
    'POST /transfers/{id}/receive' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => TransferService::receive($tx, (int) $params['id'], $input));
        inv_ok($result, 'Transfer received');
    },
    'POST /transfers/{id}/cancel' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => TransferService::cancel($tx, (int) $params['id'], $input));
        inv_ok($result, 'Transfer cancelled');
    },
    'GET /transfers' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(TransferService::listAll($pdo), 'OK');
    },
    'GET /transfers/pending' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(TransferService::listPending($pdo), 'OK');
    },
    'GET /transfers/{id}' => function (array $params) use ($pdo) {
        inv_require_auth();
        inv_ok(TransferService::get($pdo, (int) $params['id']), 'OK');
    },

    // ---- Stock Opname (PHASE C2 Section 2) ----
    'POST /stock-opname' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $sessionId = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, (int) $input['warehouse_id'], $user['id'], $input['item_ids'] ?? null));
        inv_ok(['session_id' => $sessionId], 'Opname session started');
    },
    'GET /stock-opname/{id}' => function (array $params) use ($pdo) {
        inv_require_auth();
        inv_ok(StockOpnameService::get($pdo, (int) $params['id']), 'OK');
    },
    'POST /stock-opname/{id}/count' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');
        $counts = [];
        foreach ((array) ($input['counts'] ?? []) as $row) {
            $counts[(int) $row['item_id']] = (float) $row['counted_qty_base'];
        }
        Database::transaction(fn (PDO $tx) => StockOpnameService::count($tx, (int) $params['id'], $counts, $user['id']));
        inv_ok(StockOpnameService::get($pdo, (int) $params['id']), 'Counts recorded');
    },
    'POST /stock-opname/{id}/finalize' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, (int) $params['id'], $user['id']));
        inv_ok($result, 'Opname finalized');
    },
    'POST /stock-opname/{id}/post' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');
        $overrides = [];
        foreach ((array) ($input['cost_overrides'] ?? []) as $itemId => $cost) {
            $overrides[(int) $itemId] = (float) $cost;
        }
        $result = Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, (int) $params['id'], $user['id'], $overrides));
        inv_ok($result, 'Opname posted');
    },

    // ---- Stock Adjustments (PHASE C2 Section 3) ----
    'POST /stock-adjustments' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_ADJUSTMENT_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, $input));
        inv_ok($result, 'Adjustment posted');
    },

    // ---- Production / Racik (PHASE C2 Section 4) ----
    'POST /production' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'PRODUCTION_MANAGE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        inv_require_division_scope($user, isset($input['division_id']) ? (int) $input['division_id'] : null);
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => ProductionService::create($tx, $input));
        inv_ok($result, 'Production posted');
    },
    'GET /production/{id}' => function (array $params) use ($pdo) {
        inv_require_auth();
        inv_ok(ProductionService::get($pdo, (int) $params['id']), 'OK');
    },

    // ---- Book Closing (PHASE C2 Section 5 / PHASE D0.4 next_closeable_period) ----
    'POST /book-closing/preview' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'BOOK_CLOSING_MANAGE');
        inv_ok(BookClosingService::preview($pdo, $input['period_start'], $input['period_end']), 'OK');
    },
    'POST /book-closing/close' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'BOOK_CLOSING_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => BookClosingService::close($tx, $input['period_start'], $input['period_end'], $user['id']));
        inv_ok($result, 'Period closed');
    },
    'GET /book-closing' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(BookClosingService::listAll($pdo), 'OK');
    },
    'GET /book-closing/next-closeable' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(['next_closeable_period_start' => BookClosingService::nextCloseablePeriodStart($pdo)], 'OK');
    },

    // ---- Reconciliation (PHASE E3) ----
    'GET /reconciliation' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'RECONCILIATION_VIEW');
        inv_ok(ReconciliationService::run($pdo), 'OK');
    },

    // ---- Import module (PHASE E/E2). `file_path` must be a path already on
    // the server's disk (e.g. placed by a separate upload step) — this pass
    // does not implement multipart file upload handling itself (PHASE D12
    // gap, documented in docs/PHASE_C2_ENDPOINTS.md). ----
    'POST /import/master-item/stage' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $id = ImportMasterItemService::stage($pdo, $input['file_path'], $input['file_name'] ?? basename($input['file_path']), $user['id']);
        inv_ok(['import_batch_id' => $id], 'Staged');
    },
    'POST /import/master-item/{id}/commit' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => ImportMasterItemService::commit($tx, (int) $params['id'], $user['id']));
        inv_ok($result, 'Committed');
    },

    'POST /import/{type}/stage' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $type = strtoupper($params['type']);
        if (!in_array($type, ['SUPPLIER', 'DIVISION', 'WAREHOUSE'], true)) {
            inv_error(404, 'NOT_FOUND', 'Unknown import type');
        }
        $id = ImportSimpleMasterService::stage($pdo, $type, $input['file_path'], $input['file_name'] ?? basename($input['file_path']), $user['id']);
        inv_ok(['import_batch_id' => $id], 'Staged');
    },
    'POST /import/{type}/{id}/commit' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $type = strtoupper($params['type']);
        if (!in_array($type, ['SUPPLIER', 'DIVISION', 'WAREHOUSE'], true)) {
            inv_error(404, 'NOT_FOUND', 'Unknown import type');
        }
        $result = Database::transaction(fn (PDO $tx) => ImportSimpleMasterService::commit($tx, $type, (int) $params['id'], $user['id']));
        inv_ok($result, 'Committed');
    },

    'POST /import/opening-stock/stage' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $id = ImportOpeningStockService::stage($pdo, $input['file_path'], $input['file_name'] ?? basename($input['file_path']), $user['id']);
        inv_ok(['stock_opening_id' => $id], 'Staged');
    },
    'POST /import/opening-stock/{id}/commit' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => ImportOpeningStockService::commit($tx, (int) $params['id'], $user['id']));
        inv_ok($result, 'Committed');
    },

    'POST /import/historical/stage' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $id = ImportHistoricalTransactionService::stage($pdo, $input['file_path'], $input['file_name'] ?? basename($input['file_path']), $user['id']);
        inv_ok(['import_batch_id' => $id], 'Staged');
    },
    'POST /import/historical/{id}/commit' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => ImportHistoricalTransactionService::commit($tx, (int) $params['id'], $user['id']));
        inv_ok($result, 'Committed');
    },
];

// ---- dispatch: exact match first, then {param} patterns ----
$key = "{$method} {$path}";
if (isset($routes[$key])) {
    $routes[$key]();
    exit;
}

foreach ($routes as $routeKey => $handler) {
    [$routeMethod, $routePath] = explode(' ', $routeKey, 2);
    if ($routeMethod !== $method || !str_contains($routePath, '{')) {
        continue;
    }
    $pattern = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $routePath) . '$#';
    if (preg_match($pattern, $path, $matches)) {
        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        $handler($params);
        exit;
    }
}

inv_error(404, 'NOT_FOUND', "No route for {$key}");
