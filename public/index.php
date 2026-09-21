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
require_once __DIR__ . '/../services/StockReportService.php';
require_once __DIR__ . '/../services/TransactionHistoryService.php';
require_once __DIR__ . '/../services/SupplierService.php';
require_once __DIR__ . '/../services/BakeryDestinationService.php';
require_once __DIR__ . '/../services/MasterDataSafetyService.php';
require_once __DIR__ . '/../services/WarehouseReportService.php';
require_once __DIR__ . '/../services/TraceService.php';
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
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/ImportMasterItemService.php';
require_once __DIR__ . '/../services/ImportSimpleMasterService.php';
require_once __DIR__ . '/../services/ImportOpeningStockService.php';
require_once __DIR__ . '/../services/ImportTemplateService.php';
require_once __DIR__ . '/../services/OpeningValidationService.php';
require_once __DIR__ . '/../services/CostNormalizationService.php';
require_once __DIR__ . '/../services/OpeningReconciliationService.php';
require_once __DIR__ . '/../services/MovementReconciliationReviewService.php';
require_once __DIR__ . '/../services/ImportHistoricalTransactionService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';
require_once __DIR__ . '/../services/InventoryMovementReportService.php';
require_once __DIR__ . '/../services/InventoryReconciliationReportService.php';
require_once __DIR__ . '/../services/InventorySummaryReportService.php';
require_once __DIR__ . '/../services/TransferReportService.php';
require_once __DIR__ . '/../services/StockOpnameReportService.php';
require_once __DIR__ . '/../services/AdjustmentReportService.php';
require_once __DIR__ . '/../services/ExpiryReportService.php';
require_once __DIR__ . '/../services/SlowMovementReportService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';

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
use App\Services\StockReportService;
use App\Services\TransactionHistoryService;
use App\Services\SupplierService;
use App\Services\BakeryDestinationService;
use App\Services\MasterDataSafetyService;
use App\Services\WarehouseReportService;
use App\Services\TraceService;
use App\Services\InventoryHppReportService;
use App\Services\ExcelWriterService;
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
use App\Services\ImportTemplateService;
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

/**
 * Error envelope — frozen shape, see file docblock. Frontend branches on
 * `code`, never on `message`. PHASE V2.5: `$details` is a purely additive,
 * optional extra key (e.g. `dependencies` for a blocked void/reversal) —
 * omitted entirely (not even present as null) for every pre-V2.5 caller, so
 * the frozen `{code, message}` shape is unchanged for every existing
 * consumer that doesn't pass it.
 */
function inv_error(int $httpStatus, string $code, string $message, ?array $details = null): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    $error = ['code' => $code, 'message' => $message];
    if ($details !== null) {
        $error += $details;
    }
    echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        // PHASE V2.5 — transaction correction / void / transfer reversal.
        OpeningProtectedException::class                    => ['code' => 422, 'label' => 'OPENING_PROTECTED'],
        TransactionAlreadyVoidException::class              => ['code' => 409, 'label' => 'TRANSACTION_ALREADY_VOID'],
        TransferAlreadyReversedException::class             => ['code' => 409, 'label' => 'TRANSFER_ALREADY_REVERSED'],
        VoidHasDownstreamDependenciesException::class       => ['code' => 422, 'label' => 'VOID_HAS_DOWNSTREAM_DEPENDENCIES'],
        TransferReversalHasDownstreamDependenciesException::class => ['code' => 422, 'label' => 'TRANSFER_REVERSAL_HAS_DOWNSTREAM_DEPENDENCIES'],
        ValidationException::class                => ['code' => 422, 'label' => $importPrefixed ? 'IMPORT_VALIDATION_FAILED' : 'VALIDATION_ERROR'],
    ];
    // PHASE V2.5: the two dependency exceptions carry a structured
    // `dependencies` list the frontend renders verbatim (spec: "Then list
    // dependency transaction IDs") — checked before the generic loop so the
    // extra key rides along without touching every other error path.
    if ($e instanceof VoidHasDownstreamDependenciesException) {
        inv_error(422, 'VOID_HAS_DOWNSTREAM_DEPENDENCIES', $e->getMessage(), ['dependencies' => $e->dependencies]);
    }
    if ($e instanceof TransferReversalHasDownstreamDependenciesException) {
        inv_error(422, 'TRANSFER_REVERSAL_HAS_DOWNSTREAM_DEPENDENCIES', $e->getMessage(), ['dependencies' => $e->dependencies]);
    }
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

/**
 * Same "STOCK is always forced to their own warehouse, never a
 * company-wide rollup" pattern as GET /reports/stock and GET
 * /reports/transactions — centralized here since the HPP report has 5
 * routes that all need it identically.
 */
function inv_hpp_resolve_warehouse_scope(array $user, ?int $requestedWarehouseId): ?int
{
    if ($user['role_code'] === 'STOCK') {
        if (empty($user['warehouse_id'])) {
            inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
        }
        return (int) $user['warehouse_id'];
    }
    if ($requestedWarehouseId !== null) {
        inv_require_warehouse_scope($user, $requestedWarehouseId);
    }
    return $requestedWarehouseId;
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
    // PHASE V2.1: extended with optional search/active/sort/linked_item_count —
    // every existing caller passing no query params gets the exact same
    // unfiltered, name-ascending, full-column list as before.
    'GET /suppliers' => function () use ($pdo, $query) {
        inv_require_auth();
        $where = ['1=1'];
        $bind = [];
        $q = trim((string) ($query['search'] ?? $query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(s.name LIKE :q_n OR s.code LIKE :q_c OR s.contact_name LIKE :q_ct OR s.address LIKE :q_a OR s.email LIKE :q_e)';
            $bind['q_n'] = $bind['q_c'] = $bind['q_ct'] = $bind['q_a'] = $bind['q_e'] = '%' . $q . '%';
        }
        $active = strtoupper((string) ($query['active'] ?? ''));
        if ($active === 'ACTIVE') {
            $where[] = 's.is_active = 1';
        } elseif ($active === 'INACTIVE') {
            $where[] = 's.is_active = 0';
        }
        $sortMap = ['name' => 's.name', 'newest' => 's.created_at', 'oldest' => 's.created_at', 'linked_items' => 'linked_item_count'];
        $sortKey = $sortMap[$query['sort'] ?? 'name'] ?? 's.name';
        $dir = strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' || ($query['sort'] ?? '') === 'oldest' ? 'DESC' : 'ASC';
        if (($query['sort'] ?? '') === 'newest') {
            $dir = 'DESC';
        }
        $sql = "
            SELECT s.*, COALESCE(li.cnt, 0) AS linked_item_count
            FROM suppliers s
            LEFT JOIN (SELECT default_supplier_id, COUNT(*) AS cnt FROM items WHERE default_supplier_id IS NOT NULL GROUP BY default_supplier_id) li
                ON li.default_supplier_id = s.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$sortKey} {$dir}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        inv_ok($stmt->fetchAll(), 'OK');
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
    'DELETE /suppliers/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_SUPPLIER_MANAGE');
        $supplierId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id');
        $existing->execute(['id' => $supplierId]);
        $supplier = $existing->fetch();
        if ($supplier === false) {
            inv_error(404, 'NOT_FOUND', 'supplier not found');
        }

        $refs = MasterDataSafetyService::checkSupplierReferences($pdo, $supplierId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'SUPPLIER_DELETE_ATTEMPT', 'suppliers', $supplierId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by items/transaction data');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Vendor '{$supplier['name']}' memiliki referensi barang/transaksi dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        $pdo->prepare('DELETE FROM suppliers WHERE id = :id')->execute(['id' => $supplierId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'SUPPLIER_DELETE_SUCCESS', 'suppliers', $supplierId, ['name' => $supplier['name']], null, null);
        inv_ok(['success' => true], 'Supplier permanently deleted');
    },

    // PHASE V2: Master Bakery Tujuan — a distribution endpoint for OUT
    // transactions, deliberately separate from warehouses/divisions (see
    // docs/PHASE_V2_TECHNICAL_DESIGN.md Section 5). GET is authenticated-
    // only (no special permission) since every STOCK user posting an OUT
    // needs this list; write is gated on MASTER_BAKERY_DESTINATION_MANAGE.
    // PHASE V2.1: extended with optional search/city/route/active/sort —
    // every existing caller passing no query params gets the exact same
    // unfiltered, name-ascending, full-column list as before.
    'GET /bakery-destinations' => function () use ($pdo, $query) {
        inv_require_auth();
        $where = ['1=1'];
        $bind = [];
        $q = trim((string) ($query['search'] ?? $query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(code LIKE :q_c OR name LIKE :q_n)';
            $bind['q_c'] = $bind['q_n'] = '%' . $q . '%';
        }
        $city = trim((string) ($query['city_area'] ?? ''));
        if ($city !== '') {
            $where[] = 'city_area LIKE :city';
            $bind['city'] = '%' . $city . '%';
        }
        $route = trim((string) ($query['route_cluster'] ?? ''));
        if ($route !== '') {
            $where[] = 'route_cluster LIKE :route';
            $bind['route'] = '%' . $route . '%';
        }
        $active = strtoupper((string) ($query['active'] ?? ''));
        if ($active === 'ACTIVE') {
            $where[] = 'is_active = 1';
        } elseif ($active === 'INACTIVE') {
            $where[] = 'is_active = 0';
        }
        $sortMap = ['name' => 'name', 'area' => 'city_area', 'newest' => 'created_at', 'oldest' => 'created_at'];
        $sortKey = $sortMap[$query['sort'] ?? 'name'] ?? 'name';
        $dir = ($query['sort'] ?? '') === 'oldest' ? 'ASC' : (($query['sort'] ?? '') === 'newest' ? 'DESC' : (strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC'));
        $sql = 'SELECT * FROM bakery_destinations WHERE ' . implode(' AND ', $where) . " ORDER BY {$sortKey} {$dir}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        inv_ok($stmt->fetchAll(), 'OK');
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
    'DELETE /bakery-destinations/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_BAKERY_DESTINATION_MANAGE');
        $bdId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM bakery_destinations WHERE id = :id');
        $existing->execute(['id' => $bdId]);
        $bd = $existing->fetch();
        if ($bd === false) {
            inv_error(404, 'NOT_FOUND', 'bakery destination not found');
        }

        $refs = MasterDataSafetyService::checkBakeryDestinationReferences($pdo, $bdId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'BAKERY_DESTINATION_DELETE_ATTEMPT', 'bakery_destinations', $bdId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by OUT transactions');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Bakery Tujuan '{$bd['name']}' sudah direferensikan oleh transaksi OUT dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        $pdo->prepare('DELETE FROM bakery_destinations WHERE id = :id')->execute(['id' => $bdId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'BAKERY_DESTINATION_DELETE_SUCCESS', 'bakery_destinations', $bdId, ['name' => $bd['name']], null, null);
        inv_ok(['success' => true], 'Bakery destination permanently deleted');
    },

    // PHASE V2: category master (read-only route here; write is
    // MASTER_CATEGORY_MANAGE-gated, added alongside for the same reason
    // suppliers/bakery-destinations need both a list and a manage path).
    // PHASE V2.1: extended with optional search/active/sort/item_count —
    // every existing caller passing no query params gets the exact same
    // unfiltered, name-ascending, full-column list as before (still every
    // category, active or not, by default).
    'GET /categories' => function () use ($pdo, $query) {
        inv_require_auth();
        $where = ['1=1'];
        $bind = [];
        $q = trim((string) ($query['search'] ?? $query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.code LIKE :q_c OR c.name LIKE :q_n)';
            $bind['q_c'] = $bind['q_n'] = '%' . $q . '%';
        }
        $active = strtoupper((string) ($query['active'] ?? ''));
        if ($active === 'ACTIVE') {
            $where[] = 'c.is_active = 1';
        } elseif ($active === 'INACTIVE') {
            $where[] = 'c.is_active = 0';
        }
        $sortMap = ['name' => 'c.name', 'item_count' => 'item_count'];
        $sortKey = $sortMap[$query['sort'] ?? 'name'] ?? 'c.name';
        $dir = strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $sql = "
            SELECT c.*, COALESCE(ic.cnt, 0) AS item_count
            FROM categories c
            LEFT JOIN (SELECT category_id, COUNT(*) AS cnt FROM items WHERE category_id IS NOT NULL GROUP BY category_id) ic
                ON ic.category_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$sortKey} {$dir}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        inv_ok($stmt->fetchAll(), 'OK');
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
    'DELETE /categories/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_CATEGORY_MANAGE');
        $categoryId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM categories WHERE id = :id');
        $existing->execute(['id' => $categoryId]);
        $category = $existing->fetch();
        if ($category === false) {
            inv_error(404, 'NOT_FOUND', 'category not found');
        }

        $refs = MasterDataSafetyService::checkCategoryReferences($pdo, $categoryId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'CATEGORY_DELETE_ATTEMPT', 'categories', $categoryId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by items');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Kategori '{$category['name']}' memiliki barang terkait dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $categoryId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'CATEGORY_DELETE_SUCCESS', 'categories', $categoryId, ['code' => $category['code'], 'name' => $category['name']], null, null);
        inv_ok(['success' => true], 'Category permanently deleted');
    },

    // PHASE V2.1: extended with optional search/active/sort query params —
    // every existing caller that passes none of them (the original
    // behavior) gets the exact same unfiltered, name-ascending list as
    // before.
    'GET /divisions' => function () use ($pdo, $query) {
        inv_require_auth();
        $where = ['1=1'];
        $bind = [];
        $q = trim((string) ($query['search'] ?? $query['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'name LIKE :q';
            $bind['q'] = '%' . $q . '%';
        }
        $active = strtoupper((string) ($query['active'] ?? ''));
        if ($active === 'ACTIVE') {
            $where[] = 'is_active = 1';
        } elseif ($active === 'INACTIVE') {
            $where[] = 'is_active = 0';
        }
        $dir = strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $sql = 'SELECT * FROM divisions WHERE ' . implode(' AND ', $where) . " ORDER BY name {$dir}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        inv_ok($stmt->fetchAll(), 'OK');
    },
    'PUT /divisions/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_DIVISION_MANAGE');
        $divId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM divisions WHERE id = :id');
        $existing->execute(['id' => $divId]);
        $before = $existing->fetch();
        if ($before === false) {
            inv_error(404, 'NOT_FOUND', 'division not found');
        }

        $name = array_key_exists('name', $input) ? trim((string) $input['name']) : $before['name'];
        if ($name === '') {
            throw new ValidationException(['name cannot be blank']);
        }
        $isActive = array_key_exists('is_active', $input) ? (int) (bool) $input['is_active'] : (int) $before['is_active'];

        $pdo->prepare('UPDATE divisions SET name = :n, is_active = :a WHERE id = :id')
            ->execute(['n' => $name, 'a' => $isActive, 'id' => $divId]);

        AuditService::log(
            $pdo, $user['id'], $user['username'],
            (int) $before['is_active'] !== $isActive ? ($isActive === 0 ? 'DIVISION_DEACTIVATE' : 'DIVISION_ACTIVATE') : 'DIVISION_UPDATE',
            'divisions', $divId,
            ['name' => $before['name'], 'is_active' => (int) $before['is_active']],
            ['name' => $name, 'is_active' => $isActive],
            null
        );

        inv_ok(['success' => true, 'division_id' => $divId], 'Division updated');
    },
    'DELETE /divisions/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_DIVISION_MANAGE');
        $divId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM divisions WHERE id = :id');
        $existing->execute(['id' => $divId]);
        $div = $existing->fetch();
        if ($div === false) {
            inv_error(404, 'NOT_FOUND', 'division not found');
        }

        $refs = MasterDataSafetyService::checkDivisionReferences($pdo, $divId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'DIVISION_DELETE_ATTEMPT', 'divisions', $divId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by transaction/history data');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Divisi '{$div['name']}' memiliki referensi transaksi/user dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        $pdo->prepare('DELETE FROM divisions WHERE id = :id')->execute(['id' => $divId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'DIVISION_DELETE_SUCCESS', 'divisions', $divId, ['name' => $div['name']], null, null);
        inv_ok(['success' => true], 'Division permanently deleted');
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

    // ---- PHASE V2: GET /reports/stock — "Laporan Stok" (Section 7 of the
    // technical design). GET /items is intentionally left untouched; this
    // is the paginated/filterable/sortable all-items report. ----
    'GET /reports/stock' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;

        if ($user['role_code'] === 'STOCK') {
            // STOCK is always forced to their own warehouse — never a
            // company-wide rollup, and never another warehouse even if
            // explicitly requested.
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            if ($warehouseId !== null) {
                inv_require_warehouse_scope($user, $warehouseId);
            } else {
                $warehouseId = (int) $user['warehouse_id'];
            }
        } elseif ($warehouseId !== null) {
            inv_require_warehouse_scope($user, $warehouseId);
        }

        $params = [
            'warehouse_id' => $warehouseId,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'q' => $query['q'] ?? null,
            'status' => $query['status'] ?? null,
            'include_zero_stock' => !isset($query['include_zero_stock']) || $query['include_zero_stock'] !== '0',
            'active_only' => !isset($query['active_only']) || $query['active_only'] !== '0',
            'page' => (int) ($query['page'] ?? 1),
            'per_page' => (int) ($query['per_page'] ?? 50),
            'sort' => $query['sort'] ?? 'name',
            'dir' => $query['dir'] ?? 'asc',
        ];

        if (($query['format'] ?? '') === 'csv') {
            $rows = StockReportService::exportAll($pdo, $params);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="laporan-stok-' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            // PHP 8.4 deprecates relying on fputcsv()'s implicit default $escape —
            // passed explicitly here (',', '"', '\\') to keep byte-identical CSV
            // output to every PHP version before 8.4, pre-existing bug unrelated
            // to V2.6, surfaced by this sandbox's PHP 8.4 runtime during the
            // V2.6B full-regression run.
            fputcsv($out, ['SKU', 'Nama Barang', 'Kategori', 'Satuan', 'Qty', 'Nilai', 'Rata-rata Biaya', 'Minimum', 'Buffer', 'Buffer Dikonfigurasi', 'Status', 'Terakhir Masuk', 'Terakhir Keluar', 'Terakhir Bergerak'], ',', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['sku'], $r['name'], $r['category']['name'] ?? '', $r['unit']['code'],
                    $r['qty_base'], $r['value'], $r['average_cost'], $r['minimum_stock'], $r['buffer_stock'],
                    $r['buffer_configured'] ? 'Ya' : 'Tidak', $r['status'], $r['last_in'], $r['last_out'], $r['last_movement'],
                ], ',', '"', '\\');
            }
            fclose($out);
            exit;
        }

        inv_ok(StockReportService::list($pdo, $params), 'OK');
    },

    // ---- PHASE V2: GET /reports/transactions — "History Transaksi"
    // (Section 8 of the technical design). Same warehouse-scope pattern
    // as /reports/stock: STOCK always forced to their own, others may
    // filter by any warehouse or omit it for a company-wide view. ----
    'GET /reports/transactions' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;

        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            if ($warehouseId !== null) {
                inv_require_warehouse_scope($user, $warehouseId);
            } else {
                $warehouseId = (int) $user['warehouse_id'];
            }
        } elseif ($warehouseId !== null) {
            inv_require_warehouse_scope($user, $warehouseId);
        }

        $params = [
            'warehouse_id' => $warehouseId,
            'item_id' => isset($query['item_id']) && $query['item_id'] !== '' ? (int) $query['item_id'] : null,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'transaction_type' => $query['transaction_type'] ?? null,
            'date_from' => $query['date_from'] ?? null,
            'date_to' => $query['date_to'] ?? null,
            'supplier_id' => isset($query['supplier_id']) && $query['supplier_id'] !== '' ? (int) $query['supplier_id'] : null,
            'bakery_destination_id' => isset($query['bakery_destination_id']) && $query['bakery_destination_id'] !== '' ? (int) $query['bakery_destination_id'] : null,
            'q' => $query['q'] ?? null,
            'page' => (int) ($query['page'] ?? 1),
            'per_page' => (int) ($query['per_page'] ?? 50),
            'sort' => $query['sort'] ?? 'date',
            'dir' => $query['dir'] ?? 'desc',
        ];

        inv_ok(TransactionHistoryService::list($pdo, $params), 'OK');
    },

    'GET /reports/transactions/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $transactionId = (int) $params['id'];

        // Re-derive the transaction's REAL warehouse from the DB — never
        // trust anything from the request for this scope check.
        $scope = $pdo->prepare('SELECT warehouse_id FROM inventory_transactions WHERE id = :id');
        $scope->execute(['id' => $transactionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'transaction not found');
        }
        inv_require_warehouse_scope($user, (int) $warehouseId);

        $includeAudit = AuthService::hasPermission($pdo, $user['role_code'], 'AUDIT_LOG_VIEW');

        inv_ok(TransactionHistoryService::detail($pdo, $transactionId, $includeAudit), 'OK');
    },

    // ============================================================
    // PHASE V2.3 — "Laporan Nilai Stok & HPP" (Inventory Value & HPP
    // Reconciliation). Strictly read-only, same INVENTORY_VIEW gate as the
    // other reports above, same STOCK-forced-to-own-warehouse pattern
    // (inv_hpp_resolve_warehouse_scope). Every number in the response is
    // computed by InventoryHppReportService straight from the existing
    // ledger/FIFO tables — no new tables, no duplicated FIFO logic. Trace
    // drill-down is deliberately NOT implemented here: every row carries
    // real transaction_id/item_id/warehouse_id/batch_id so the frontend
    // opens them through the EXISTING TraceDrawer/TraceService endpoints.
    // ============================================================
    'GET /reports/inventory-hpp/summary' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;
        $q = isset($query['q']) && $query['q'] !== '' ? (string) $query['q'] : null;

        inv_ok(InventoryHppReportService::summary($pdo, $start, $end, $warehouseId, $categoryId, $q), 'OK');
    },

    'GET /reports/inventory-hpp/warehouses' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryHppReportService::warehouseBreakdown($pdo, $start, $end, $warehouseId), 'OK');
    },

    'GET /reports/inventory-hpp/daily' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;
        $q = isset($query['q']) && $query['q'] !== '' ? (string) $query['q'] : null;
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = in_array((int) ($query['per_page'] ?? 10), [10, 25, 50], true) ? (int) ($query['per_page'] ?? 10) : 10;

        inv_ok(InventoryHppReportService::dailyRecap($pdo, $start, $end, $warehouseId, $page, $perPage, $categoryId, $q), 'OK');
    },

    // The "Trace Detail HPP" panel's data for one date. Every returned line
    // carries transaction_id — the frontend opens it via the existing
    // TraceDrawer.openTransaction(), never a second trace implementation.
    'GET /reports/inventory-hpp/day-detail' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $date = (string) ($query['date'] ?? '');
        if ($date === '' || strtotime($date) === false) {
            inv_error(422, 'VALIDATION_ERROR', 'date is required (YYYY-MM-DD)');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryHppReportService::dayDetail($pdo, $date, $warehouseId), 'OK');
    },

    // The exact bridge behind the Variance KPI — "clicking Variance" data.
    // Never forces the reconciliation to balance: `unexplained` is returned
    // as computed, and the frontend shows a warning if it isn't ~0.
    'GET /reports/inventory-hpp/variance-bridge' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryHppReportService::varianceBridge($pdo, $start, $end, $warehouseId), 'OK');
    },

    'GET /reports/inventory-hpp/export' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;
        $q = isset($query['q']) && $query['q'] !== '' ? (string) $query['q'] : null;

        $sheets = InventoryHppReportService::buildExportSheets($pdo, $start, $end, $warehouseId, $categoryId, $q);
        $tmpPath = tempnam(sys_get_temp_dir(), 'hpp_export_');
        try {
            ExcelWriterService::write($tmpPath, $sheets);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="laporan-nilai-stok-hpp-' . date('Ymd_His') . '.xlsx"');
            header('Content-Length: ' . filesize($tmpPath));
            readfile($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
        exit;
    },

    // ============================================================
    // PHASE V2.6B — Reporting Pack: Report 2 "Pergerakan Stok Harian" +
    // Report 14 "Rekonsiliasi Arus Stok". Strictly read-only, same
    // INVENTORY_VIEW gate and STOCK-forced-to-own-warehouse pattern as
    // the HPP report above (inv_hpp_resolve_warehouse_scope is fully
    // generic despite its name — reused verbatim, never duplicated).
    // Drill-down carries real transaction_id so the frontend opens it via
    // the EXISTING TraceDrawer — no second trace implementation here.
    // ============================================================
    'GET /reports/movement/daily' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryMovementReportService::dailyMovement($pdo, $start, $end, $warehouseId), 'OK');
    },

    'GET /reports/movement/day-breakdown' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $date = (string) ($query['date'] ?? '');
        if ($date === '' || strtotime($date) === false) {
            inv_error(422, 'VALIDATION_ERROR', 'date is required (YYYY-MM-DD)');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryMovementReportService::dayBreakdown($pdo, $date, $warehouseId), 'OK');
    },

    'GET /reports/movement/day-transactions' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $date = (string) ($query['date'] ?? '');
        $category = (string) ($query['category'] ?? '');
        if ($date === '' || strtotime($date) === false || $category === '') {
            inv_error(422, 'VALIDATION_ERROR', 'date and category are required');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryMovementReportService::categoryTransactions($pdo, $date, $warehouseId, $category), 'OK');
    },

    // MANDATORY CORRECTION A — historical (inventory_effect=0) disclosure
    // drill-down for one date's underlying transactions.
    'GET /reports/movement/historical-transactions' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $date = (string) ($query['date'] ?? '');
        if ($date === '' || strtotime($date) === false) {
            inv_error(422, 'VALIDATION_ERROR', 'date is required (YYYY-MM-DD)');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryMovementReportService::historicalTransactions($pdo, $date, $warehouseId), 'OK');
    },

    'GET /reports/reconciliation/movement' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryReconciliationReportService::run($pdo, $start, $end, $warehouseId), 'OK');
    },

    // PHASE V2.6B — Report 1 "Ringkasan Inventory" (management overview).
    'GET /reports/summary/inventory' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;

        inv_ok(InventorySummaryReportService::summary($pdo, $start, $end, $warehouseId, $categoryId), 'OK');
    },

    // ============================================================
    // PHASE V2.6B — remaining Reporting Pack items (Reports 4,5,6,8,9,
    // 10,11,12,13). Report 3 (Laporan Stok) reuses GET /reports/stock
    // verbatim (StockReportService, unchanged) and Report 15 (Audit
    // Transaksi) reuses GET /audit-logs verbatim (TraceService, unchanged)
    // — no new route for either. Every route below is INVENTORY_VIEW-gated
    // read-only, STOCK-forced-to-own-warehouse via inv_hpp_resolve_warehouse_scope.
    // ============================================================

    // Report 4 — Laporan Pembelian: qualifying purchase = type IN, POSTED,
    // never a transfer/production/opening/adjustment (TransactionHistoryService's
    // own type filter already excludes everything else by construction).
    'GET /reports/purchase' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $params = [
            'warehouse_id' => $warehouseId, 'transaction_type' => 'IN',
            'supplier_id' => isset($query['supplier_id']) && $query['supplier_id'] !== '' ? (int) $query['supplier_id'] : null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
            'q' => $query['q'] ?? null,
            'is_historical_import' => isset($query['historical']) && $query['historical'] === '1' ? true : (isset($query['historical']) && $query['historical'] === '0' ? false : null),
            'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
        ];
        if ($params['is_historical_import'] === null) {
            unset($params['is_historical_import']);
        }
        inv_ok(TransactionHistoryService::list($pdo, $params), 'OK');
    },
    'GET /reports/purchase/summary' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        inv_ok(TransactionHistoryService::summary($pdo, [
            'warehouse_id' => $warehouseId, 'transaction_type' => 'IN',
            'supplier_id' => isset($query['supplier_id']) && $query['supplier_id'] !== '' ? (int) $query['supplier_id'] : null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
        ], 'supplier_id'), 'OK');
    },

    // Report 11 — Pembelian per Supplier: same qualifying-purchase
    // definition as Report 4, grouped.
    'GET /reports/purchase/by-supplier' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        inv_ok(TransactionHistoryService::groupedSummary($pdo, [
            'warehouse_id' => $warehouseId, 'transaction_type' => 'IN',
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
        ], 'supplier_id', 'supplier_name'), 'OK');
    },

    // Report 5 — Laporan IN/OUT: unified operational view of both types.
    'GET /reports/in-out' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $direction = $query['direction'] ?? '';
        $types = in_array($direction, ['IN', 'OUT'], true) ? [$direction] : ['IN', 'OUT'];
        inv_ok(TransactionHistoryService::list($pdo, [
            'warehouse_id' => $warehouseId, 'transaction_types' => $types,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'item_id' => isset($query['item_id']) && $query['item_id'] !== '' ? (int) $query['item_id'] : null,
            'supplier_id' => isset($query['supplier_id']) && $query['supplier_id'] !== '' ? (int) $query['supplier_id'] : null,
            'bakery_destination_id' => isset($query['bakery_destination_id']) && $query['bakery_destination_id'] !== '' ? (int) $query['bakery_destination_id'] : null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
            'q' => $query['q'] ?? null,
            'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
        ]), 'OK');
    },
    'GET /reports/in-out/summary' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $base = [
            'warehouse_id' => $warehouseId,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
        ];
        $inSummary = TransactionHistoryService::summary($pdo, $base + ['transaction_type' => 'IN']);
        $outSummary = TransactionHistoryService::summary($pdo, $base + ['transaction_type' => 'OUT']);
        inv_ok([
            'total_in_value' => $inSummary['total_value'], 'total_out_value' => $outSummary['total_value'],
            'net_movement' => round($inSummary['total_value'] - $outSummary['total_value'], 4),
            'transaction_count' => $inSummary['transaction_count'] + $outSummary['transaction_count'],
        ], 'OK');
    },

    // Report 12 — Distribusi per Bakery: qualifying OUT rows with a real
    // bakery_destination_id — a plain warehouse transfer (bakery_destination_id
    // is NULL by the chk_tx_bakery_destination_out_only constraint on any
    // non-OUT type) can never appear here.
    'GET /reports/distribution/bakery' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        inv_ok(TransactionHistoryService::groupedSummary($pdo, [
            'warehouse_id' => $warehouseId, 'transaction_type' => 'OUT',
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
        ], 'bakery_destination_id', 'bakery_destination_name'), 'OK');
    },

    // Report 6 — Laporan Transfer.
    'GET /reports/transfer' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        inv_ok(TransferReportService::list($pdo, [
            'warehouse_id' => $warehouseId,
            'from_warehouse_id' => isset($query['from_warehouse_id']) && $query['from_warehouse_id'] !== '' ? (int) $query['from_warehouse_id'] : null,
            'to_warehouse_id' => isset($query['to_warehouse_id']) && $query['to_warehouse_id'] !== '' ? (int) $query['to_warehouse_id'] : null,
            'status' => $query['status'] ?? null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
            'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
        ]), 'OK');
    },

    // Report 8 — Stock Opname.
    'GET /reports/opname' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        inv_ok(StockOpnameReportService::list($pdo, [
            'warehouse_id' => $warehouseId, 'status' => $query['status'] ?? null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
            'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
        ]), 'OK');
    },

    // Report 9 — Adjustment / Selisih.
    'GET /reports/adjustment' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        inv_ok(AdjustmentReportService::list($pdo, [
            'warehouse_id' => $warehouseId,
            'direction' => in_array($query['direction'] ?? '', ['POSITIVE', 'NEGATIVE'], true) ? $query['direction'] : null,
            'adjustment_type' => $query['adjustment_type'] ?? null,
            'item_id' => isset($query['item_id']) && $query['item_id'] !== '' ? (int) $query['item_id'] : null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
            'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
        ]), 'OK');
    },

    // Report 10 — Expired / Near Expired.
    'GET /reports/expiry' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        inv_ok(ExpiryReportService::list($pdo, [
            'warehouse_id' => $warehouseId,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'status' => $query['status'] ?? null,
            'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
        ]), 'OK');
    },

    // Report 13 — Slow / No Movement.
    'GET /reports/slow-movement' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        inv_ok(SlowMovementReportService::list($pdo, [
            'warehouse_id' => $warehouseId,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'threshold_days' => (int) ($query['threshold_days'] ?? 30),
            'include_zero_stock' => isset($query['include_zero_stock']) && $query['include_zero_stock'] === '1',
            'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
        ]), 'OK');
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

    // PHASE V2.5: the RECEIVED-transfer correction flow. Privileged-only
    // (TRANSFER_REVERSE is granted to SUPERADMIN/ADMIN, never STOCK — see
    // the migration) — deliberately no per-warehouse scope check beyond the
    // permission gate itself, the same pattern POST /transactions/{id}/void
    // already uses for TRANSACTION_VOID: a correction action's authority
    // comes from the permission, not from being "inside" one of the two
    // warehouses the original transfer touched.
    'POST /transfers/{id}/reverse' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSFER_REVERSE');

        $transferId = (int) $params['id'];
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];

        $result = Database::transaction(
            fn (PDO $tx) => TransferService::reverse(
                $tx,
                $transferId,
                $input
            )
        );

        inv_ok($result, 'Transfer reversed');
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

    // ============================================================
    // PHASE V2.2 — Trace Center. Strictly read-only (GET only, TraceService
    // only ever SELECTs — see its own docblock for the architecture
    // decision: every correlation here is an existing FK, no new tables).
    // Gated on the same AUDIT_LOG_VIEW permission GET /audit-logs already
    // uses — this is audit/governance visibility, not a new access tier.
    // A STOCK user's warehouse is always re-derived from their own account
    // row, never trusted from the request.
    // ============================================================
    'GET /trace/search' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $q = trim((string) ($query['q'] ?? ''));
        if ($q === '') {
            inv_ok([], 'OK');
        }
        $validTypes = ['item', 'transaction', 'supplier', 'bakery_destination', 'category', 'warehouse', 'transfer', 'user', 'opname', 'production', 'opening', 'import', 'role'];
        $type = in_array($query['type'] ?? '', $validTypes, true) ? $query['type'] : null;
        $limit = min(50, max(1, (int) ($query['limit'] ?? 20)));
        inv_ok(TraceService::search($pdo, $q, $type, $limit), 'OK');
    },

    'GET /trace/events' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = in_array((int) ($query['per_page'] ?? 25), [25, 50, 100], true) ? (int) ($query['per_page'] ?? 25) : 25;
        inv_ok(TraceService::browseEvents($pdo, [
            'entity_type' => $query['entity_type'] ?? null,
            'action_code' => $query['action_code'] ?? null,
            'username' => $query['username'] ?? null,
            'date_from' => $query['date_from'] ?? null,
            'date_to' => $query['date_to'] ?? null,
            'dir' => $query['dir'] ?? 'desc',
            'page' => $page,
            'per_page' => $perPage,
        ]), 'OK');
    },

    'GET /trace/entity' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $type = (string) ($query['type'] ?? '');
        $id = (int) ($query['id'] ?? 0);
        if ($id <= 0) {
            inv_error(422, 'VALIDATION_ERROR', 'id is required');
        }
        if ($type === 'warehouse') {
            inv_require_warehouse_scope($user, $id);
        }
        $result = TraceService::entityTrace($pdo, $type, $id);
        if ($type === 'stock_policy' && isset($result['overview']['warehouse_id'])) {
            inv_require_warehouse_scope($user, (int) $result['overview']['warehouse_id']);
        }
        inv_ok($result, 'OK');
    },

    'GET /trace/transaction/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $result = TraceService::transactionTrace($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $result['transaction']['warehouse_id']);
        inv_ok($result, 'OK');
    },

    'GET /trace/inventory' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $itemId = (int) ($query['item_id'] ?? 0);
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        if ($itemId <= 0) {
            inv_error(422, 'VALIDATION_ERROR', 'item_id is required');
        }
        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            $warehouseId = (int) $user['warehouse_id'];
        } elseif ($warehouseId === null) {
            inv_error(422, 'VALIDATION_ERROR', 'warehouse_id is required');
        } else {
            inv_require_warehouse_scope($user, $warehouseId);
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = in_array((int) ($query['per_page'] ?? 50), [25, 50, 100], true) ? (int) ($query['per_page'] ?? 50) : 50;
        inv_ok(TraceService::inventoryTrace($pdo, $itemId, $warehouseId, $page, $perPage), 'OK');
    },

    // ============================================================
    // PHASE V2.2B — closes the coverage gap the owner flagged after V2.2:
    // Transfer/Opname/Production/Opening were PARTIAL (traceable only via
    // their underlying transaction, not directly), Import/User/Role were
    // dead ends. Same read-only, AUDIT_LOG_VIEW-gated pattern as the block
    // above. User/Role additionally require SUPERADMIN/ADMIN — account and
    // permission structure is more sensitive than inventory movement, so
    // audit-view alone isn't enough for those two.
    // ============================================================
    'GET /trace/transfer/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $result = TraceService::transferTrace($pdo, (int) $params['id']);
        if ($user['role_code'] === 'STOCK') {
            $wh = (int) ($user['warehouse_id'] ?? 0);
            $inScope = $wh > 0 && ($wh === (int) $result['transfer']['from_warehouse_id'] || $wh === (int) $result['transfer']['to_warehouse_id']);
            if (!$inScope) {
                inv_error(403, 'FORBIDDEN', 'Transfer is outside your assigned warehouse');
            }
        }
        inv_ok($result, 'OK');
    },

    'GET /trace/opname/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $result = TraceService::opnameTrace($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $result['session']['warehouse_id']);
        inv_ok($result, 'OK');
    },

    'GET /trace/production/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $result = TraceService::productionTrace($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $result['production']['warehouse_id']);
        inv_ok($result, 'OK');
    },

    'GET /trace/opening/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $result = TraceService::openingTrace($pdo, (int) $params['id']);
        // An opening batch can span multiple warehouses; a STOCK user only
        // ever sees the lines for their own warehouse, never the others —
        // same "silently scope, don't 403 the whole record" behavior as
        // GET /items/report and GET /trace/inventory.
        if ($user['role_code'] === 'STOCK') {
            $wh = (int) ($user['warehouse_id'] ?? 0);
            $result['lines'] = array_values(array_filter(
                $result['lines'],
                static fn ($l) => (int) ($l['staging_line']['warehouse_id'] ?? 0) === $wh
            ));
            if ($result['lines'] === []) {
                inv_error(403, 'FORBIDDEN', 'This opening batch has no lines in your assigned warehouse');
            }
        }
        inv_ok($result, 'OK');
    },

    'GET /trace/import/{id}' => function (array $params) use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = in_array((int) ($query['per_page'] ?? 50), [25, 50, 100], true) ? (int) ($query['per_page'] ?? 50) : 50;
        inv_ok(TraceService::importTrace($pdo, (int) $params['id'], $page, $perPage), 'OK');
    },

    'GET /trace/user/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        if (!in_array($user['role_code'], ['ADMIN', 'SUPERADMIN'], true)) {
            inv_error(403, 'FORBIDDEN', 'User trace is restricted to ADMIN/SUPERADMIN');
        }
        inv_ok(TraceService::userTrace($pdo, (int) $params['id']), 'OK');
    },

    'GET /trace/role/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        if (!in_array($user['role_code'], ['ADMIN', 'SUPERADMIN'], true)) {
            inv_error(403, 'FORBIDDEN', 'Role trace is restricted to ADMIN/SUPERADMIN');
        }
        inv_ok(TraceService::roleTrace($pdo, (int) $params['id']), 'OK');
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

    // PHASE V2.6A: "Download Template Excel" — headers come straight from
    // ImportTemplateService, which is built from the exact accepted-column
    // list of the four importer services above (never a hand-maintained
    // copy that can drift). Read-only, generates the file fresh on every
    // request, no staging/commit/inventory effect whatsoever.
    'GET /import/template/{type}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $type = strtoupper($params['type']);
        if (!in_array($type, ['MASTER_ITEM', 'SUPPLIER', 'DIVISION', 'WAREHOUSE'], true)) {
            inv_error(404, 'NOT_FOUND', 'Unknown import template type');
        }
        $sheets = ImportTemplateService::build($type);
        $tmpPath = tempnam(sys_get_temp_dir(), 'import_template_');
        try {
            ExcelWriterService::write($tmpPath, $sheets);
            $fileName = 'template-import-' . strtolower(str_replace('_', '-', $type)) . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($tmpPath));
            readfile($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
        exit;
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

    // ============================================================
    // PHASE V2.1 — Master Barang enhanced list + safe edit/delete.
    // GET /items/report is a NEW, additive endpoint — GET /items stays
    // byte-for-byte untouched (docs/PHASE_4_TASK_B_GET_ITEMS_DEBT.md).
    // Reuses StockReportService::list()/exportAll() — same single source
    // of truth as "Stok Barang" — extended this phase with supplier_id,
    // item_status (tri-state Semua/Aktif/Tidak Aktif), stock_status
    // (Ada Stok/Stok 0/Need Attention), and an updated_at sort key. Sort
    // keys are looked up in a fixed whitelist array (StockReportService::
    // SORTABLE) — an unrecognized sort value silently falls back to name,
    // never concatenated into SQL.
    // ============================================================
    'GET /items/report' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            if ($warehouseId !== null) {
                inv_require_warehouse_scope($user, $warehouseId);
            } else {
                $warehouseId = (int) $user['warehouse_id'];
            }
        } elseif ($warehouseId !== null) {
            inv_require_warehouse_scope($user, $warehouseId);
        }

        $itemStatus = strtoupper((string) ($query['active'] ?? ''));
        $itemStatus = in_array($itemStatus, ['ACTIVE', 'INACTIVE'], true) ? $itemStatus : null;
        $stockStatus = strtoupper((string) ($query['stock_status'] ?? ''));
        $stockStatus = in_array($stockStatus, ['HAS_STOCK', 'ZERO_STOCK', 'NEEDS_ATTENTION'], true) ? $stockStatus : null;
        $sort = $query['sort'] ?? 'name';
        $validSorts = ['name', 'sku', 'qty', 'value', 'status', 'updated_at'];
        $sort = in_array($sort, $validSorts, true) ? $sort : 'name';
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = in_array((int) ($query['per_page'] ?? 25), [25, 50, 100], true) ? (int) ($query['per_page'] ?? 25) : 25;

        $params = [
            'warehouse_id' => $warehouseId,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'supplier_id' => isset($query['supplier_id']) && $query['supplier_id'] !== '' ? (int) $query['supplier_id'] : null,
            'q' => $query['search'] ?? $query['q'] ?? null,
            'status' => in_array($query['status'] ?? '', ['SAFE', 'LOW', 'CRITICAL', 'OUT_OF_STOCK', 'MIGRATION_NEGATIVE_REVIEW'], true) ? $query['status'] : null,
            'stock_status' => $stockStatus,
            'item_status' => $itemStatus,
            'active_only' => $itemStatus === null, // no tri-state override given -> default to active-only, same convention as GET /reports/stock
            'include_zero_stock' => true,
            'page' => $page,
            'per_page' => $perPage,
            'sort' => $sort,
            'dir' => strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
        ];

        inv_ok(StockReportService::list($pdo, $params), 'OK');
    },

    // Controlled master-data fields only — name/category/supplier/barcode/
    // notes/status. base_unit_id and minimum_stock (FIFO-sensitive /
    // stock-policy-owned) are deliberately never accepted here; minimum/
    // buffer stay on the existing PUT /stock-policy workflow.
    'PUT /items/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_ITEM_MANAGE');
        $itemId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM items WHERE id = :id');
        $existing->execute(['id' => $itemId]);
        $before = $existing->fetch();
        if ($before === false) {
            inv_error(404, 'NOT_FOUND', 'item not found');
        }

        $name = array_key_exists('name', $input) ? trim((string) $input['name']) : $before['name'];
        if ($name === '') {
            throw new ValidationException(['name cannot be blank']);
        }
        $categoryId = array_key_exists('category_id', $input) ? ($input['category_id'] !== null ? (int) $input['category_id'] : null) : $before['category_id'];
        $supplierId = array_key_exists('default_supplier_id', $input) ? ($input['default_supplier_id'] !== null ? (int) $input['default_supplier_id'] : null) : $before['default_supplier_id'];
        $barcode = array_key_exists('barcode', $input) ? (($input['barcode'] === null || trim((string) $input['barcode']) === '') ? null : trim((string) $input['barcode'])) : $before['barcode'];
        $notes = array_key_exists('notes', $input) ? (($input['notes'] === null) ? null : trim((string) $input['notes'])) : $before['notes'];
        $status = array_key_exists('status', $input) ? strtoupper((string) $input['status']) : $before['status'];
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            throw new ValidationException(["status must be ACTIVE or INACTIVE, got '{$status}'"]);
        }

        $pdo->prepare('UPDATE items SET name = :n, category_id = :c, default_supplier_id = :s, barcode = :b, notes = :notes, status = :status WHERE id = :id')
            ->execute(['n' => $name, 'c' => $categoryId, 's' => $supplierId, 'b' => $barcode, 'notes' => $notes, 'status' => $status, 'id' => $itemId]);

        AuditService::log(
            $pdo, $user['id'], $user['username'],
            $before['status'] !== $status ? ($status === 'INACTIVE' ? 'ITEM_DEACTIVATE' : 'ITEM_ACTIVATE') : 'ITEM_UPDATE',
            'items', $itemId,
            ['name' => $before['name'], 'category_id' => $before['category_id'], 'default_supplier_id' => $before['default_supplier_id'], 'barcode' => $before['barcode'], 'status' => $before['status']],
            ['name' => $name, 'category_id' => $categoryId, 'default_supplier_id' => $supplierId, 'barcode' => $barcode, 'status' => $status],
            null
        );

        inv_ok(['success' => true, 'item_id' => $itemId], 'Item updated');
    },

    'DELETE /items/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_ITEM_MANAGE');
        $itemId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM items WHERE id = :id');
        $existing->execute(['id' => $itemId]);
        $item = $existing->fetch();
        if ($item === false) {
            inv_error(404, 'NOT_FOUND', 'item not found');
        }

        $refs = MasterDataSafetyService::checkItemReferences($pdo, $itemId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'ITEM_DELETE_ATTEMPT', 'items', $itemId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by transaction/history data');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Barang '{$item['name']}' memiliki referensi transaksi/history dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        Database::transaction(function (PDO $tx) use ($itemId) {
            // Owned structural setup data, not business history (see the
            // comment on MasterDataSafetyService::checkItemReferences) —
            // removed here, in the same transaction as the item itself,
            // rather than relying on a DB-level cascade this schema does
            // not define.
            $tx->prepare('DELETE FROM item_unit_conversions WHERE item_id = :id')->execute(['id' => $itemId]);
            $tx->prepare('DELETE FROM items WHERE id = :id')->execute(['id' => $itemId]);
        });
        AuditService::log($pdo, $user['id'], $user['username'], 'ITEM_DELETE_SUCCESS', 'items', $itemId, ['name' => $item['name'], 'sku' => $item['sku']], null, null);
        inv_ok(['success' => true], 'Item permanently deleted');
    },

    // ============================================================
    // PHASE V2.1 — Master Gudang enhanced list + safe edit/delete.
    // Never creates or lists Karang Tengah unless it already exists as a
    // real row — this endpoint just reads whatever warehouses table
    // already has, the same as GET /warehouses always has.
    // ============================================================
    'GET /warehouses/report' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $singleWarehouseId = null;
        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            $singleWarehouseId = (int) $user['warehouse_id'];
        }

        $active = strtoupper((string) ($query['active'] ?? ''));
        $active = in_array($active, ['ACTIVE', 'INACTIVE'], true) ? $active : null;
        $sort = in_array($query['sort'] ?? '', ['name', 'sku_count', 'qty', 'value'], true) ? $query['sort'] : 'name';

        $rows = WarehouseReportService::list($pdo, [
            'q' => $query['search'] ?? $query['q'] ?? null,
            'active' => $active,
            'sort' => $sort,
            'dir' => strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            'warehouse_id' => $singleWarehouseId,
        ]);
        inv_ok(['rows' => $rows, 'total' => count($rows)], 'OK');
    },

    'PUT /warehouses/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_WAREHOUSE_MANAGE');
        $whId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM warehouses WHERE id = :id');
        $existing->execute(['id' => $whId]);
        $before = $existing->fetch();
        if ($before === false) {
            inv_error(404, 'NOT_FOUND', 'warehouse not found');
        }

        $name = array_key_exists('name', $input) ? trim((string) $input['name']) : $before['name'];
        if ($name === '') {
            throw new ValidationException(['name cannot be blank']);
        }
        $isActive = array_key_exists('is_active', $input) ? (int) (bool) $input['is_active'] : (int) $before['is_active'];

        $pdo->prepare('UPDATE warehouses SET name = :n, is_active = :a WHERE id = :id')
            ->execute(['n' => $name, 'a' => $isActive, 'id' => $whId]);

        AuditService::log(
            $pdo, $user['id'], $user['username'],
            (int) $before['is_active'] !== $isActive ? ($isActive === 0 ? 'WAREHOUSE_DEACTIVATE' : 'WAREHOUSE_ACTIVATE') : 'WAREHOUSE_UPDATE',
            'warehouses', $whId,
            ['name' => $before['name'], 'is_active' => (int) $before['is_active']],
            ['name' => $name, 'is_active' => $isActive],
            null
        );

        inv_ok(['success' => true, 'warehouse_id' => $whId], 'Warehouse updated');
    },

    'DELETE /warehouses/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_WAREHOUSE_MANAGE');
        $whId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM warehouses WHERE id = :id');
        $existing->execute(['id' => $whId]);
        $wh = $existing->fetch();
        if ($wh === false) {
            inv_error(404, 'NOT_FOUND', 'warehouse not found');
        }

        $refs = MasterDataSafetyService::checkWarehouseReferences($pdo, $whId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'WAREHOUSE_DELETE_ATTEMPT', 'warehouses', $whId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by transaction/history data');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Gudang '{$wh['name']}' memiliki stok/riwayat transaksi dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        $pdo->prepare('DELETE FROM warehouses WHERE id = :id')->execute(['id' => $whId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'WAREHOUSE_DELETE_SUCCESS', 'warehouses', $whId, ['code' => $wh['code'], 'name' => $wh['name']], null, null);
        inv_ok(['success' => true], 'Warehouse permanently deleted');
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
