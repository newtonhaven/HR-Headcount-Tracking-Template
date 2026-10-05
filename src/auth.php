<?php
declare(strict_types=1);

// Users, sessions and passwords. Accounts live in the `users` table; the first administrator
// is created on the command line with bin/create-user.php (there is no default account).

const ROLE_ADMIN = 'admin';
const ROLE_VIEWER = 'viewer';
const ROLES = [ROLE_ADMIN, ROLE_VIEWER];
const PASSWORD_MIN_LENGTH = 10;

/** bcrypt hash of a random, discarded string: keeps failed sign-ins for unknown users as slow as for known ones. */
const DUMMY_PASSWORD_HASH = '$2y$10$fIhBQ6yJ0NnXdyw5Jb91weAH6wxmvgYdr59GBa12uoesdhRSIZmUi';

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
}

/**
 * The signed-in user, re-read from the database on every request so that disabled accounts,
 * role changes and password resets take effect immediately.
 *
 * @return array{id: int, username: string, name: string, role: string}|null
 */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $id = $_SESSION['user_id'] ?? null;
    if ($id) {
        $stmt = db()->prepare('SELECT id, username, full_name, role, session_version FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && (int) $row['session_version'] === (int) ($_SESSION['session_version'] ?? 0)) {
            $user = ['id' => (int) $row['id'], 'username' => $row['username'], 'name' => $row['full_name'], 'role' => $row['role']];
        } else {
            unset($_SESSION['user_id'], $_SESSION['session_version']);
        }
    }
    return $user;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? null) === ROLE_ADMIN;
}

function user_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

// Sign-in throttling: after too many failures in the window, further attempts are refused.
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_FAILURES_PER_USER = 5;
const LOGIN_MAX_FAILURES_PER_IP = 30;

/** @return 'ok'|'invalid'|'locked' */
function attempt_login(string $username, string $password): string
{
    $db = db();
    $username = mb_substr($username, 0, 50);
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);

    $window = 'attempted_at > NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE';
    $stmt = $db->prepare("SELECT
            (SELECT COUNT(*) FROM login_attempts WHERE username = ? AND $window) AS user_failures,
            (SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND $window) AS ip_failures");
    $stmt->execute([$username, $ip]);
    $failures = $stmt->fetch();
    if ($failures['user_failures'] >= LOGIN_MAX_FAILURES_PER_USER || $failures['ip_failures'] >= LOGIN_MAX_FAILURES_PER_IP) {
        return 'locked';
    }

    $stmt = $db->prepare('SELECT id, password_hash, session_version FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    // Verify against a dummy hash when the user does not exist, so response times do not reveal valid usernames.
    $hash = $row['password_hash'] ?? DUMMY_PASSWORD_HASH;
    if (!password_verify($password, $hash) || !$row) {
        $db->prepare('INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)')->execute([$username, $ip]);
        $db->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
        return 'invalid';
    }
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    $db->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$row['id']]);
    $db->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['session_version'] = (int) $row['session_version'];
    return 'ok';
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Guard for HTML pages: redirects to the login page (or dashboard) when access is not allowed. */
function require_page_access(?string $role = null): array
{
    start_session();
    $user = current_user();
    if (!$user) {
        header('Location: /index.php');
        exit;
    }
    if ($role === ROLE_ADMIN && $user['role'] !== ROLE_ADMIN) {
        header('Location: /app/index.php?denied=1');
        exit;
    }
    return $user;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ---------------------------------------------------------------------------
// Account management (shared by the Users API and bin/create-user.php)
// ---------------------------------------------------------------------------

function validate_username(string $username): string
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        throw new ApiException('Username must be 3–50 characters: letters, digits, dot, dash or underscore.', 422);
    }
    return $username;
}

function validate_password(string $password): string
{
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        throw new ApiException('Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.', 422);
    }
    if (strlen($password) > 72) {
        throw new ApiException('Password must be at most 72 bytes.', 422); // bcrypt limit
    }
    return $password;
}

function validate_role(string $role): string
{
    if (!in_array($role, ROLES, true)) {
        throw new ApiException('Role must be admin or viewer.', 422);
    }
    return $role;
}

function create_user(string $username, string $fullName, string $role, string $password): int
{
    $fullName = trim($fullName);
    if ($fullName === '' || mb_strlen($fullName) > 100) {
        throw new ApiException('Full name is required (max 100 characters).', 422);
    }
    db()->prepare('INSERT INTO users (username, full_name, role, password_hash) VALUES (?, ?, ?, ?)')->execute([
        validate_username($username),
        $fullName,
        validate_role($role),
        password_hash(validate_password($password), PASSWORD_DEFAULT),
    ]);
    return (int) db()->lastInsertId();
}

/** Set a new password and end every other session of that user. */
function set_password(int $userId, string $password): void
{
    db()->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')
        ->execute([password_hash(validate_password($password), PASSWORD_DEFAULT), $userId]);
}

/** Keep the current session valid after the signed-in user changed their own password. */
function refresh_session_version(int $userId): void
{
    $stmt = db()->prepare('SELECT session_version FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['session_version'] = (int) $stmt->fetchColumn();
    session_regenerate_id(true);
    session_write_close();
}
