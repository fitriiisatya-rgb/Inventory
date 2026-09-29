<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'user.manage');
$pdo = Database::pdo();
$method = $_SERVER['REQUEST_METHOD'];

$validRoles = ['SUPERADMIN', 'ADMIN', 'SUPERVISOR', 'APPROVER', 'COUNTER', 'VIEWER'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id, username, full_name, job_title, role, team, status, created_at FROM users ORDER BY full_name');
    Response::json(['data' => $stmt->fetchAll()]);
}

Csrf::requireValid();
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$username = trim((string) ($input['username'] ?? ''));
$fullName = trim((string) ($input['full_name'] ?? ''));
$jobTitle = trim((string) ($input['job_title'] ?? '')) ?: null;
$role     = (string) ($input['role'] ?? '');
$team     = (string) ($input['team'] ?? '') ?: null;
$status   = in_array($input['status'] ?? '', ['ACTIVE', 'INACTIVE'], true) ? $input['status'] : 'ACTIVE';

if (!in_array($role, $validRoles, true)) {
    Response::error('Role tidak valid.', 422);
}
if ($role !== 'COUNTER') {
    $team = null;
} elseif (!in_array($team, ['P1', 'P2'], true)) {
    Response::error('Team (P1/P2) wajib diisi untuk role COUNTER.', 422);
}

if ($method === 'POST') {
    $password = (string) ($input['password'] ?? '');
    if ($username === '' || $fullName === '' || strlen($password) < 8) {
        Response::error('username, full_name wajib diisi dan password minimal 8 karakter.', 422);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, job_title, role, team, status) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$username, $hash, $fullName, $jobTitle, $role, $team, $status]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            Response::error("Username '{$username}' sudah digunakan.", 409);
        }
        throw $e;
    }
    $id = (int) $pdo->lastInsertId();
    $row = fetchUserSafe($pdo, $id);
    Audit::log($user['id'], 'USER_CREATE', 'users', $id, null, $row);
    Response::json(['data' => $row], 201);
}

if ($method === 'PUT') {
    $id = (int) ($input['id'] ?? 0);
    $old = fetchUserSafe($pdo, $id);
    if (!$old) {
        Response::error('User tidak ditemukan.', 404);
    }
    if ($username === '' || $fullName === '') {
        Response::error('username dan full_name wajib diisi.', 422);
    }

    $sql = 'UPDATE users SET username=?, full_name=?, job_title=?, role=?, team=?, status=?';
    $params = [$username, $fullName, $jobTitle, $role, $team, $status];

    if (!empty($input['password'])) {
        if (strlen((string) $input['password']) < 8) {
            Response::error('Password minimal 8 karakter.', 422);
        }
        $sql .= ', password_hash=?';
        $params[] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
    }
    $sql .= ' WHERE id=?';
    $params[] = $id;

    try {
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            Response::error("Username '{$username}' sudah digunakan.", 409);
        }
        throw $e;
    }
    $new = fetchUserSafe($pdo, $id);
    Audit::log($user['id'], 'USER_UPDATE', 'users', $id, $old, $new);
    Response::json(['data' => $new]);
}

Response::error('Method not allowed', 405);

function fetchUserSafe(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, username, full_name, job_title, role, team, status, created_at, updated_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
