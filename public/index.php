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
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/StockPolicyService.php';
require_once __DIR__ . '/../services/SupplierService.php';
require_once __DIR__ . '/../services/BakeryDestinationService.php';
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
require_once __DIR__ . '/../services/SystemHealthService.php';
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/ImportMasterItemService.php';
require_once __DIR__ . '/../services/ImportSimpleMasterService.php';
require_once __DIR__ . '/../services/ImportOpeningStockService.php';
require_once __DIR__ . '/../services/OpeningValidationService.php';
require_once __DIR__ . '/../services/CostNormalizationService.php';
require_once __DIR__ . '/../services/OpeningReconciliationService.php';
require_once __DIR__ . '/../services/MovementReconciliationReviewService.php';
require_once __DIR__ . '/../services/ImportHistoricalTransactionService.php';

use App\Services\AuthService;
use App\Services\Database;
use App\Services\InsufficientStockException;
use App\Services\NegativeStockException;
use App\Services\PriceAnomalyException;
use App\Services\PeriodLockedException;
use App\Services\WarehouseLockedException;
use App\Services\CostRequiredException;
use App\Services\UnitConversionNotApprovedException;
use App\Services\NegativeMigrationStockRequiresAdjustmentException;
use App\Services\MigrationNegativeStockService;
use App\Services\StockPolicyService;
use App\Services\SupplierService;
use App\Services\BakeryDestinationService;
use App\Services\AuditService;
use App\Services\RateLimitedException;
use App\Services\NotFoundException;
use App\Services\TransferAlreadyReceivedException;
use App\Services\TransferAlreadyCancelledException;
use App\Services\DuplicateRequestException;
use App\Services\ImportValidationException;
use App\Services\OpeningReconciliationService;
use App\Services\MovementReconciliationReviewService;
use App\Services\ValidationException;
use App\Services\FifoService;
use App\Services\InventoryService;
use App\Services\TransferService;
use App\Services\StockOpnameService;
use App\Services\StockAdjustmentService;
use App\Services\ProductionService;
use App\Services\BookClosingService;
use App\Services\ReconciliationService;
use App\Services\SystemHealthService;
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
        UnitConversionNotApprovedException::class => ['code' => 422, 'label' => 'UNIT_CONVERSION_NOT_APPROVED'],
        NegativeMigrationStockRequiresAdjustmentException::class => ['code' => 422, 'label' => 'NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT'],
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

function inv_require_auth(bool $allowPasswordChangePending = false): array
{
    $user = AuthService::currentUser();
    if ($user === null) {
        inv_error(401, 'UNAUTHENTICATED', 'Not authenticated');
    }
    // PHASE G21: a provisioned account (temp generated password) can do
    // nothing except change its password until it does — enforced here so
    // every other route in this file inherits the block automatically,
    // without needing its own check.
    if (!$allowPasswordChangePending && !empty($user['must_change_password'])) {
        inv_error(403, 'PASSWORD_CHANGE_REQUIRED', 'Password must be changed before continuing');
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
        inv_ok([
            'username' => $user['username'], 'role' => $user['role_code'],
            'must_change_password' => (bool) $user['must_change_password'],
            'csrf_token' => AuthService::csrfToken(),
        ], 'Logged in');
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
    // PHASE G21: the one action allowed while must_change_password=1.
    'POST /auth/change-password' => function () use ($pdo, $input) {
        $user = inv_require_auth(true);
        AuthService::changePassword($pdo, (int) $user['id'], (string) ($input['current_password'] ?? ''), (string) ($input['new_password'] ?? ''));
        inv_ok(null, 'Password changed');
    },

    'GET /items' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT * FROM items ORDER BY name')->fetchAll(), 'OK');
    },
    'GET /warehouses' => function () use ($pdo) {
        $user = inv_require_auth();

        if ($user['role_code'] === 'STOCK' && $user['warehouse_id'] !== null) {
            $stmt = $pdo->prepare(
                'SELECT * FROM warehouses WHERE id = :id AND is_active = 1 ORDER BY name'
            );
            $stmt->execute(['id' => (int) $user['warehouse_id']]);
            inv_ok($stmt->fetchAll(), 'OK');
        }

        inv_ok(
            $pdo->query('SELECT * FROM warehouses ORDER BY name')->fetchAll(),
            'OK'
        );
    },
    'GET /suppliers' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll(), 'OK');
    },
    // PHASE V2: Master Vendor/Supplier CRUD (audited: suppliers already
    // existed as an import-only table — this is the first write path).
    // Soft-delete only via PUT .../{id} with is_active:false — never DELETE.
    'POST /suppliers' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_SUPPLIER_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => SupplierService::create($tx, $input));
        inv_ok($result, 'Supplier created');
    },
    'PUT /suppliers/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_SUPPLIER_MANAGE');
        $input['updated_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => SupplierService::update($tx, (int) $params['id'], $input));
        inv_ok($result, 'Supplier updated');
    },

    // PHASE V2: Master Bakery Tujuan — a distribution endpoint for OUT
    // transactions, deliberately separate from warehouses/divisions (see
    // docs/PHASE_V2_TECHNICAL_DESIGN.md Section 5). GET is authenticated-
    // only (no special permission) since every STOCK user posting an OUT
    // needs this list; write is gated on MASTER_BAKERY_DESTINATION_MANAGE.
    'GET /bakery-destinations' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT * FROM bakery_destinations ORDER BY name')->fetchAll(), 'OK');
    },
    'POST /bakery-destinations' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_BAKERY_DESTINATION_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => BakeryDestinationService::create($tx, $input));
        inv_ok($result, 'Bakery destination created');
    },
    'PUT /bakery-destinations/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_BAKERY_DESTINATION_MANAGE');
        $input['updated_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => BakeryDestinationService::update($tx, (int) $params['id'], $input));
        inv_ok($result, 'Bakery destination updated');
    },

    // PHASE V2: category master (read-only route here; write is
    // MASTER_CATEGORY_MANAGE-gated, added alongside for the same reason
    // suppliers/bakery-destinations need both a list and a manage path).
    'GET /categories' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll(), 'OK');
    },
    'POST /categories' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_CATEGORY_MANAGE');
        $code = trim((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw new ValidationException(['code and name cannot be blank']);
        }
        $existing = $pdo->prepare('SELECT id FROM categories WHERE code = :c');
        $existing->execute(['c' => $code]);
        if ($existing->fetchColumn() !== false) {
            throw new ValidationException(["category code '{$code}' already exists"]);
        }
        $stmt = $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)');
        $stmt->execute(['c' => $code, 'n' => $name]);
        $categoryId = (int) $pdo->lastInsertId();
        AuditService::log($pdo, $user['id'], $user['username'], 'CATEGORY_CREATE', 'categories', $categoryId, null, ['code' => $code, 'name' => $name], null);
        inv_ok(['success' => true, 'category_id' => $categoryId], 'Category created');
    },
    'PUT /categories/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_CATEGORY_MANAGE');
        $categoryId = (int) $params['id'];
        $existing = $pdo->prepare('SELECT * FROM categories WHERE id = :id');
        $existing->execute(['id' => $categoryId]);
        $before = $existing->fetch();
        if ($before === false) {
            inv_error(404, 'NOT_FOUND', 'category not found');
        }
        $name = isset($input['name']) ? trim((string) $input['name']) : $before['name'];
        if ($name === '') {
            throw new ValidationException(['name cannot be blank']);
        }
        $isActive = array_key_exists('is_active', $input) ? (int) (bool) $input['is_active'] : (int) $before['is_active'];
        $pdo->prepare('UPDATE categories SET name = :n, is_active = :a WHERE id = :id')
            ->execute(['n' => $name, 'a' => $isActive, 'id' => $categoryId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'CATEGORY_UPDATE', 'categories', $categoryId, ['name' => $before['name'], 'is_active' => (int) $before['is_active']], ['name' => $name, 'is_active' => $isActive], null);
        inv_ok(['success' => true, 'category_id' => $categoryId], 'Category updated');
    },

    'GET /divisions' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT * FROM divisions ORDER BY name')->fetchAll(), 'OK');
    },
    // Units this item may be transacted in (its base unit plus any configured
    // purchase/middle conversions) — Transaction IN/OUT forms need this to
    // offer only valid units rather than free-typed unit ids.
    'GET /items/{id}/units' => function (array $params) use ($pdo) {
        inv_require_auth();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.code, u.name, c.conversion_to_base, c.is_purchase_default
             FROM item_unit_conversions c JOIN units u ON u.id = c.unit_id
             WHERE c.item_id = :item_id AND c.valid_to IS NULL
             ORDER BY c.is_purchase_default DESC, c.conversion_to_base DESC'
        );
        $stmt->execute(['item_id' => (int) $params['id']]);
        inv_ok($stmt->fetchAll(), 'OK');
    },

    // ---- PHASE V2: per-item-per-warehouse stock policy (min/buffer) ----
    'GET /stock-policy' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $itemId = (int) ($query['item_id'] ?? 0);
        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        if ($itemId <= 0 || $warehouseId <= 0) {
            throw new ValidationException(['item_id and warehouse_id are required']);
        }

        inv_require_warehouse_scope($user, $warehouseId);

        inv_ok(StockPolicyService::resolve($pdo, $itemId, $warehouseId), 'OK');
    },

    'PUT /stock-policy' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_POLICY_MANAGE');

        $warehouseId = (int) ($input['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }
        // A STOCK-role user does not hold STOCK_POLICY_MANAGE by default (see
        // database/schema.sql's role_permissions seed), but this scope check
        // runs unconditionally for every role rather than special-casing
        // STOCK — the same "never trust warehouse_id, always re-check scope"
        // discipline applied uniformly regardless of who technically has the
        // permission today.
        inv_require_warehouse_scope($user, $warehouseId);

        $input['updated_by'] = $user['id'];
        $input['username'] = $user['username'];

        $result = Database::transaction(fn (PDO $tx) => StockPolicyService::upsert($tx, $input));
        inv_ok($result, 'Stock policy saved');
    },

    // ---- InventoryService: single source of truth reads (Section 7) ----
    'GET /inventory/current' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }

        inv_require_warehouse_scope($user, $warehouseId);

        inv_ok(
            InventoryService::currentStock(
                $pdo,
                (int) $query['item_id'],
                $warehouseId
            ),
            'OK'
        );
    },

    'GET /inventory/current/{sku}' => function (array $params) use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $item = $pdo->prepare('SELECT id FROM items WHERE sku = :sku');
        $item->execute(['sku' => $params['sku']]);
        $itemId = $item->fetchColumn();

        if ($itemId === false) {
            inv_error(404, 'NOT_FOUND', 'SKU not found');
        }

        if (isset($query['warehouse_id'])) {
            $warehouseId = (int) $query['warehouse_id'];
            inv_require_warehouse_scope($user, $warehouseId);

            inv_ok(
                InventoryService::currentStock(
                    $pdo,
                    (int) $itemId,
                    $warehouseId
                ),
                'OK'
            );
        }

        // STOCK user without warehouse_id must still remain inside
        // their assigned warehouse.
        if (
            $user['role_code'] === 'STOCK'
            && $user['warehouse_id'] !== null
        ) {
            inv_ok(
                InventoryService::currentStock(
                    $pdo,
                    (int) $itemId,
                    (int) $user['warehouse_id']
                ),
                'OK'
            );
        }

        inv_ok(
            InventoryService::currentStockAllWarehouses(
                $pdo,
                (int) $itemId
            ),
            'OK'
        );
    },

    'GET /inventory/batches' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }

        inv_require_warehouse_scope($user, $warehouseId);

        inv_ok(
            InventoryService::batches(
                $pdo,
                (int) $query['item_id'],
                $warehouseId
            ),
            'OK'
        );
    },

    'GET /inventory/value' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        if (
            $user['role_code'] === 'STOCK'
            && $user['warehouse_id'] !== null
        ) {
            inv_ok(
                InventoryService::warehouseDashboardSummary(
                    $pdo,
                    (int) $user['warehouse_id']
                ),
                'OK'
            );
        }

        inv_ok(InventoryService::companyTotalValue($pdo), 'OK');
    },

    'GET /inventory/in-transit' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        if (
            $user['role_code'] === 'STOCK'
            && $user['warehouse_id'] !== null
        ) {
            $summary = InventoryService::warehouseDashboardSummary(
                $pdo,
                (int) $user['warehouse_id']
            );

            inv_ok(
                ['in_transit_value' => $summary['in_transit_value']],
                'OK'
            );
        }

        inv_ok(
            ['in_transit_value' => InventoryService::inTransitValue($pdo)],
            'OK'
        );
    },

    'GET /inventory/ledger' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }

        inv_require_warehouse_scope($user, $warehouseId);

        inv_ok(
            InventoryService::ledger(
                $pdo,
                (int) $query['item_id'],
                $warehouseId
            ),
            'OK'
        );
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

    'GET /transfer-destinations' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $stmt = $pdo->query(
            'SELECT id, code, name
             FROM warehouses
             WHERE is_active = 1
             ORDER BY name'
        );

        $warehouses = $stmt->fetchAll();

        $sourceWarehouseId = null;

        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(
                    403,
                    'FORBIDDEN',
                    'STOCK user has no warehouse assignment'
                );
            }

            $sourceWarehouseId = (int) $user['warehouse_id'];
        }

        inv_ok([
            'source_warehouse_id' => $sourceWarehouseId,
            'warehouses' => $warehouses,
        ], 'OK');
    },

    // ---- Transfers (PHASE C2 Section 1) ----
    'POST /transfers' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $fromWarehouseId = (int) ($input['from_warehouse_id'] ?? 0);
        if ($fromWarehouseId <= 0) {
            throw new ValidationException(['from_warehouse_id is required']);
        }

        // STOCK operator may only dispatch stock from their own warehouse.
        inv_require_warehouse_scope($user, $fromWarehouseId);

        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];

        $result = Database::transaction(
            fn (PDO $tx) => TransferService::create($tx, $input)
        );

        inv_ok($result, 'Transfer created');
    },

    'POST /transfers/{id}/receive' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $transferId = (int) $params['id'];
        $transfer = TransferService::get($pdo, $transferId);

        // Only the DESTINATION warehouse may receive.
        inv_require_warehouse_scope(
            $user,
            (int) $transfer['to_warehouse_id']
        );

        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];

        $result = Database::transaction(
            fn (PDO $tx) => TransferService::receive(
                $tx,
                $transferId,
                $input
            )
        );

        inv_ok($result, 'Transfer received');
    },

    'POST /transfers/{id}/cancel' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $transferId = (int) $params['id'];
        $transfer = TransferService::get($pdo, $transferId);

        // Only the SOURCE warehouse may cancel.
        inv_require_warehouse_scope(
            $user,
            (int) $transfer['from_warehouse_id']
        );

        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];

        $result = Database::transaction(
            fn (PDO $tx) => TransferService::cancel(
                $tx,
                $transferId,
                $input
            )
        );

        inv_ok($result, 'Transfer cancelled');
    },

    'GET /transfers' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $scopeWarehouseId = null;

        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(
                    403,
                    'FORBIDDEN',
                    'STOCK user has no warehouse assignment'
                );
            }

            $scopeWarehouseId = (int) $user['warehouse_id'];
        }

        inv_ok(
            TransferService::listAll($pdo, $scopeWarehouseId),
            'OK'
        );
    },

    'GET /transfers/pending' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $scopeWarehouseId = null;

        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(
                    403,
                    'FORBIDDEN',
                    'STOCK user has no warehouse assignment'
                );
            }

            $scopeWarehouseId = (int) $user['warehouse_id'];
        }

        inv_ok(
            TransferService::listPending($pdo, $scopeWarehouseId),
            'OK'
        );
    },

    'GET /transfers/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $transfer = TransferService::get(
            $pdo,
            (int) $params['id']
        );

        if ($user['role_code'] === 'STOCK') {
            $ownWarehouseId = (int) ($user['warehouse_id'] ?? 0);

            if ($ownWarehouseId <= 0) {
                inv_error(
                    403,
                    'FORBIDDEN',
                    'STOCK user has no warehouse assignment'
                );
            }

            $fromWarehouseId =
                (int) $transfer['from_warehouse_id'];
            $toWarehouseId =
                (int) $transfer['to_warehouse_id'];

            if (
                $ownWarehouseId !== $fromWarehouseId
                && $ownWarehouseId !== $toWarehouseId
            ) {
                inv_error(
                    403,
                    'FORBIDDEN',
                    'Transfer is outside your warehouse scope'
                );
            }
        }

        inv_ok($transfer, 'OK');
    },

    // ---- Stock Opname (PHASE C2 Section 2) ----
    'POST /stock-opname' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $warehouseId = (int) ($input['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }

        inv_require_warehouse_scope($user, $warehouseId);

        $sessionId = Database::transaction(
            fn (PDO $tx) => StockOpnameService::start(
                $tx,
                $warehouseId,
                $user['id'],
                $input['item_ids'] ?? null
            )
        );

        inv_ok(['session_id' => $sessionId], 'Opname session started');
    },

    'GET /stock-opname' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $sql = 'SELECT * FROM stock_opname_sessions WHERE 1=1';
        $params = [];

        if (
            $user['role_code'] === 'STOCK'
            && $user['warehouse_id'] !== null
        ) {
            $sql .= ' AND warehouse_id = :wh';
            $params['wh'] = (int) $user['warehouse_id'];
        } elseif (isset($query['warehouse_id'])) {
            $sql .= ' AND warehouse_id = :wh';
            $params['wh'] = (int) $query['warehouse_id'];
        }

        if (isset($query['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = $query['status'];
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        inv_ok($stmt->fetchAll(), 'OK');
    },

    'GET /stock-opname/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $sessionId = (int) $params['id'];

        $scope = $pdo->prepare(
            'SELECT warehouse_id
             FROM stock_opname_sessions
             WHERE id = :id'
        );
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();

        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }

        inv_require_warehouse_scope($user, (int) $warehouseId);

        inv_ok(
            StockOpnameService::get($pdo, $sessionId),
            'OK'
        );
    },

    'POST /stock-opname/{id}/count' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $sessionId = (int) $params['id'];

        $scope = $pdo->prepare(
            'SELECT warehouse_id
             FROM stock_opname_sessions
             WHERE id = :id'
        );
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();

        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }

        inv_require_warehouse_scope($user, (int) $warehouseId);

        $counts = [];
        foreach ((array) ($input['counts'] ?? []) as $row) {
            $counts[(int) $row['item_id']] =
                (float) $row['counted_qty_base'];
        }

        Database::transaction(
            fn (PDO $tx) => StockOpnameService::count(
                $tx,
                $sessionId,
                $counts,
                $user['id']
            )
        );

        inv_ok(
            StockOpnameService::get($pdo, $sessionId),
            'Counts recorded'
        );
    },

    'POST /stock-opname/{id}/finalize' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $sessionId = (int) $params['id'];

        $scope = $pdo->prepare(
            'SELECT warehouse_id
             FROM stock_opname_sessions
             WHERE id = :id'
        );
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();

        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }

        inv_require_warehouse_scope($user, (int) $warehouseId);

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::finalize(
                $tx,
                $sessionId,
                $user['id']
            )
        );

        inv_ok($result, 'Opname finalized');
    },

    'POST /stock-opname/{id}/post' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $sessionId = (int) $params['id'];

        $scope = $pdo->prepare(
            'SELECT warehouse_id
             FROM stock_opname_sessions
             WHERE id = :id'
        );
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();

        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }

        inv_require_warehouse_scope($user, (int) $warehouseId);

        $overrides = [];
        foreach ((array) ($input['cost_overrides'] ?? []) as $itemId => $cost) {
            $overrides[(int) $itemId] = (float) $cost;
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::post(
                $tx,
                $sessionId,
                $user['id'],
                $overrides
            )
        );

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

    // ---- System Health (PHASE G25 — post-go-live monitoring) ----
    'GET /system/health' => function () use ($pdo) {
        $user = inv_require_auth();
        if (!in_array($user['role_code'], ['ADMIN', 'SUPERADMIN'], true)) {
            inv_error(403, 'FORBIDDEN', 'System health is restricted to ADMIN/SUPERADMIN');
        }
        inv_ok(SystemHealthService::check($pdo), 'OK');
    },

    // ---- Audit Log (PHASE D13) ----
    'GET /audit-logs' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');

        $where = [];
        $params = [];
        if (!empty($query['date_from'])) { $where[] = 'a.created_at >= :date_from'; $params['date_from'] = $query['date_from'] . ' 00:00:00'; }
        if (!empty($query['date_to'])) { $where[] = 'a.created_at <= :date_to'; $params['date_to'] = $query['date_to'] . ' 23:59:59'; }
        if (!empty($query['username'])) { $where[] = 'a.username_snapshot LIKE :username'; $params['username'] = '%' . $query['username'] . '%'; }
        if (!empty($query['action_code'])) { $where[] = 'a.action_code = :action_code'; $params['action_code'] = $query['action_code']; }
        if (!empty($query['entity_type'])) { $where[] = 'a.entity_type = :entity_type'; $params['entity_type'] = $query['entity_type']; }
        $sql = 'SELECT a.* FROM audit_logs a' . (empty($where) ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY a.id DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        inv_ok($stmt->fetchAll(), 'OK');
    },

    // ---- Import module (PHASE E/E2). `file_path` must be a path already on
    // the server's disk (e.g. placed by a separate upload step) — this pass
    // does not implement multipart file upload handling itself (PHASE D12
    // gap, documented in docs/PHASE_C2_ENDPOINTS.md). ----
    // PHASE D12: real multipart upload — closes the "file_path must already be
    // on disk" gap noted in docs/PHASE_C2_ENDPOINTS.md. Saved under a
    // dedicated, non-web-reachable storage/imports/ directory with a
    // generated name (never the client's own filename) so this can't be used
    // to overwrite or traverse into anything else on disk.
    'POST /import/upload' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            inv_error(422, 'VALIDATION_ERROR', 'No file uploaded (expected multipart/form-data field "file")');
        }
        $originalName = basename((string) $_FILES['file']['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            inv_error(422, 'VALIDATION_ERROR', 'Only .csv or .xlsx files are accepted');
        }
        $storageDir = __DIR__ . '/../storage/imports';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destination = $storageDir . '/' . $storedName;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            inv_error(500, 'INTERNAL_ERROR', 'Failed to store uploaded file');
        }
        inv_ok(['file_path' => $destination, 'file_name' => $originalName], 'Uploaded');
    },

    // PHASE D12: preview staged rows before commit (generic import_batches/import_rows path).
    'GET /import/batches/{id}/rows' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $batch = $pdo->prepare('SELECT * FROM import_batches WHERE id = :id');
        $batch->execute(['id' => (int) $params['id']]);
        $batch = $batch->fetch();
        if (!$batch) {
            inv_error(404, 'NOT_FOUND', 'import batch not found');
        }
        $rows = $pdo->prepare('SELECT * FROM import_rows WHERE import_batch_id = :id ORDER BY row_no');
        $rows->execute(['id' => (int) $params['id']]);
        inv_ok(['batch' => $batch, 'rows' => $rows->fetchAll()], 'OK');
    },
    // PHASE D12: preview for the Opening Stock path, which stages into stock_opening_lines instead.
    'GET /import/opening-stock/{id}/rows' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $opening = $pdo->prepare('SELECT * FROM stock_openings WHERE id = :id');
        $opening->execute(['id' => (int) $params['id']]);
        $opening = $opening->fetch();
        if (!$opening) {
            inv_error(404, 'NOT_FOUND', 'stock opening batch not found');
        }
        $lines = $pdo->prepare(
            'SELECT sol.*, i.sku, i.name FROM stock_opening_lines sol JOIN items i ON i.id = sol.item_id
             WHERE stock_opening_id = :id ORDER BY sol.id'
        );
        $lines->execute(['id' => (int) $params['id']]);
        inv_ok(['opening' => $opening, 'lines' => $lines->fetchAll()], 'OK');
    },

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
    // PHASE G-DATA 2 Section 12: GO_LIVE_READY gate for a staged final
    // opening batch — read-only, never mutates data.
    'GET /import/opening-stock/{id}/reconciliation' => function (array $params) use ($pdo) {
        inv_require_auth();
        inv_ok(OpeningReconciliationService::report($pdo, (int) $params['id']), 'OK');
    },
    // PHASE G-DATA 2 Section 11: the 8 historical movement-reconciliation
    // rows, kept deliberately separate from unit-conversion and opening
    // questions. Read-only list; verified_final_opening is filled in via a
    // separate step once the owner's final stock file confirms it.
    'GET /movement-reconciliation-reviews' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(MovementReconciliationReviewService::list($pdo), 'OK');
    },
    // POLICY CORRECTION — the admin review list for the owner-approved
    // migration-negative whitelist: current balance, MIGRATION_NEGATIVE_REVIEW/
    // NEEDS_STOCK_OPNAME status (computed live from current stock), and the
    // historical_* evidence to drill back into from the live negative
    // opening. One endpoint, read by the dashboard, stock list, SKU detail,
    // and reconciliation report alike (they can also all read the same
    // flags directly off GET /items/{id}/stock via InventoryService).
    'GET /migration-negative-review' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(MigrationNegativeStockService::reviewList($pdo), 'OK');
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
