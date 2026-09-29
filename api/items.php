<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireLogin();
$pdo = Database::pdo();
$method = $_SERVER['REQUEST_METHOD'];

function itemRowFromInput(array $input): array
{
    return [
        'sku'            => trim((string) ($input['sku'] ?? '')),
        'barcode'        => trim((string) ($input['barcode'] ?? '')) ?: null,
        'name'           => trim((string) ($input['name'] ?? '')),
        'category_id'    => (int) ($input['category_id'] ?? 0),
        'brand'          => trim((string) ($input['brand'] ?? '')) ?: null,
        'distributor'    => trim((string) ($input['distributor'] ?? '')) ?: null,
        'buy_unit'       => trim((string) ($input['buy_unit'] ?? '')),
        'buy_content'    => $input['buy_content'] ?? null,
        'mid_unit'       => isset($input['mid_unit']) && trim((string) $input['mid_unit']) !== '' ? trim((string) $input['mid_unit']) : null,
        'mid_content'    => $input['mid_content'] ?? null,
        'base_unit'      => trim((string) ($input['base_unit'] ?? '')),
        // NULL means "never recorded" — never coerced to 0 (design review point 5).
        'last_buy_price' => (isset($input['last_buy_price']) && $input['last_buy_price'] !== '')
            ? $input['last_buy_price'] : null,
        'status'         => in_array($input['status'] ?? '', ['ACTIVE', 'INACTIVE'], true) ? $input['status'] : 'ACTIVE',
        'note'           => trim((string) ($input['note'] ?? '')) ?: null,
    ];
}

if ($method === 'GET') {
    $where  = [];
    $params = [];

    $status = $_GET['status'] ?? 'ACTIVE';
    if (in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
        $where[] = 'i.status = ?';
        $params[] = $status;
    } // 'ALL' -> no filter

    if (!empty($_GET['category_id'])) {
        $where[] = 'i.category_id = ?';
        $params[] = (int) $_GET['category_id'];
    }

    if (!empty($_GET['q'])) {
        $where[] = '(i.sku LIKE ? OR i.name LIKE ? OR i.barcode LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        array_push($params, $like, $like, $like);
    }

    $sql = 'SELECT i.*, c.name AS category_name FROM items i JOIN categories c ON c.id = i.category_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY i.name LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    Response::json(['data' => $stmt->fetchAll()]);
}

Permissions::require($user['role'], 'master.manage');
Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$data = itemRowFromInput($input);

if ($data['sku'] === '' || $data['name'] === '' || $data['category_id'] <= 0) {
    Response::error('sku, name, dan category_id wajib diisi.', 422);
}

$issues = UnitConversion::validateConversion(
    $data['buy_unit'], $data['buy_content'], $data['mid_unit'], $data['mid_content'], $data['base_unit']
);
if (UnitConversion::hasBlockingErrors($issues)) {
    Response::error('Konfigurasi konversi satuan tidak valid.', 422, ['issues' => $issues]);
}

if ($method === 'POST') {
    $stmt = $pdo->prepare(
        'INSERT INTO items (sku, barcode, name, category_id, brand, distributor,
            buy_unit, buy_content, mid_unit, mid_content, base_unit, last_buy_price, status, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    try {
        $stmt->execute([
            $data['sku'], $data['barcode'], $data['name'], $data['category_id'], $data['brand'], $data['distributor'],
            $data['buy_unit'], $data['buy_content'], $data['mid_unit'], $data['mid_content'], $data['base_unit'],
            $data['last_buy_price'], $data['status'], $data['note'],
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            Response::error("SKU '{$data['sku']}' sudah digunakan.", 409);
        }
        throw $e;
    }
    $id = (int) $pdo->lastInsertId();
    $row = fetchItem($pdo, $id);
    Audit::log($user['id'], 'ITEM_CREATE', 'items', $id, null, $row);
    Response::json(['data' => $row, 'warnings' => array_values(array_filter($issues, fn($i) => $i['severity'] === 'warning'))], 201);
}

if ($method === 'PUT') {
    $id = (int) ($input['id'] ?? 0);
    $old = fetchItem($pdo, $id);
    if (!$old) {
        Response::error('Item tidak ditemukan.', 404);
    }
    $stmt = $pdo->prepare(
        'UPDATE items SET sku=?, barcode=?, name=?, category_id=?, brand=?, distributor=?,
            buy_unit=?, buy_content=?, mid_unit=?, mid_content=?, base_unit=?, last_buy_price=?, status=?, note=?
         WHERE id=?'
    );
    try {
        $stmt->execute([
            $data['sku'], $data['barcode'], $data['name'], $data['category_id'], $data['brand'], $data['distributor'],
            $data['buy_unit'], $data['buy_content'], $data['mid_unit'], $data['mid_content'], $data['base_unit'],
            $data['last_buy_price'], $data['status'], $data['note'], $id,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            Response::error("SKU '{$data['sku']}' sudah digunakan.", 409);
        }
        throw $e;
    }
    $new = fetchItem($pdo, $id);
    Audit::log($user['id'], 'ITEM_UPDATE', 'items', $id, $old, $new);
    Response::json(['data' => $new, 'warnings' => array_values(array_filter($issues, fn($i) => $i['severity'] === 'warning'))]);
}

Response::error('Method not allowed', 405);

function fetchItem(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
