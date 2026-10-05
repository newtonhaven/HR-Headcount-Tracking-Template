<?php
declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

// The signed-in user's own account: POST {current_password, new_password} changes the password
// and signs out the user's other sessions.

$me = api_start(ROLE_VIEWER);
if (request_method() !== 'POST') {
    json_error('Method not allowed.', 405);
}

$data = request_json();
$stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$me['id']]);
if (!password_verify((string) ($data['current_password'] ?? ''), (string) $stmt->fetchColumn())) {
    json_error('Your current password is not correct.', 422);
}
$new = (string) ($data['new_password'] ?? '');
if (hash_equals((string) ($data['current_password'] ?? ''), $new)) {
    json_error('Choose a password that differs from the current one.', 422);
}
set_password($me['id'], $new);
refresh_session_version($me['id']);
json_response(['changed' => true]);
