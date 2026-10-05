<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

// User accounts (admin only). Accounts are disabled rather than deleted.

$me = api_start();
if ($me['role'] !== ROLE_ADMIN) {
    json_error('Only administrators can manage users.', 403);
}
$db = db();

function find_user(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT id, username, full_name, role, is_active, last_login_at, created_at FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new ApiException('User not found.', 404);
    }
    $user['is_active'] = (bool) $user['is_active'];
    return $user;
}

function other_active_admins(PDO $db, int $exceptId): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?");
    $stmt->execute([$exceptId]);
    return (int) $stmt->fetchColumn();
}

switch (request_method()) {
    case 'GET':
        $rows = $db->query('SELECT id, username, full_name, role, is_active, last_login_at, created_at FROM users ORDER BY is_active DESC, full_name')->fetchAll();
        foreach ($rows as &$row) {
            $row['is_active'] = (bool) $row['is_active'];
        }
        unset($row);
        json_response($rows);

    case 'POST':
        $data = request_json();
        $id = create_user(
            (string) ($data['username'] ?? ''),
            (string) ($data['full_name'] ?? ''),
            (string) ($data['role'] ?? ROLE_VIEWER),
            (string) ($data['password'] ?? '')
        );
        json_response(find_user($db, $id), 201);

    case 'PATCH':
        // {full_name}, {role}, {is_active} and/or {password} (admin reset).
        $id = query_id('id', true);
        $user = find_user($db, $id);
        $data = request_json();
        $isSelf = $id === $me['id'];

        $removesAdmin = (array_key_exists('role', $data) && $data['role'] !== ROLE_ADMIN && $user['role'] === ROLE_ADMIN)
            || (array_key_exists('is_active', $data) && !$data['is_active'] && $user['role'] === ROLE_ADMIN);
        if ($removesAdmin && $isSelf) {
            json_error('You cannot remove your own administrator access.', 422);
        }
        if ($removesAdmin && other_active_admins($db, $id) === 0) {
            json_error('At least one active administrator must remain.', 422);
        }

        $fields = [];
        $params = [];
        if (array_key_exists('full_name', $data)) {
            $fields[] = 'full_name = ?';
            $params[] = require_text($data, 'full_name', 'Full name', 100);
        }
        if (array_key_exists('role', $data)) {
            $fields[] = 'role = ?';
            $params[] = validate_role((string) $data['role']);
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = ?';
            $params[] = $data['is_active'] ? 1 : 0;
        }
        if ($fields) {
            $params[] = $id;
            $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }
        if (array_key_exists('password', $data)) {
            set_password($id, (string) $data['password']);
            if ($isSelf) {
                refresh_session_version($id);
            }
        }
        json_response(find_user($db, $id));

    default:
        json_error('Method not allowed.', 405);
}
