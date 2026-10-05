<?php
declare(strict_types=1);

// Shared bootstrap for pages, API endpoints and CLI scripts: config, database and JSON helpers.
// Users, sessions and CSRF live in auth.php.

require_once __DIR__ . '/Workload.php';
require_once __DIR__ . '/StaffingService.php';
require_once __DIR__ . '/auth.php';

// PHP and the database must agree on what "today" is (active employees, turnover periods).
$timezone = getenv('TZ') ?: 'UTC';
date_default_timezone_set(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC');
unset($timezone);

function config(string $key)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config[$key] ?? null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

/** Escape a value for HTML output. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// JSON API helpers
// ---------------------------------------------------------------------------

final class ApiException extends RuntimeException
{
}

/**
 * Entry point for every API script: requires a logged-in user, and for state-changing
 * requests also the admin role (unless $writeRole says otherwise) plus a valid CSRF token.
 */
function api_start(string $writeRole = ROLE_ADMIN): array
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    set_exception_handler(function (Throwable $ex) {
        if ($ex instanceof ApiException) {
            json_error($ex->getMessage(), $ex->getCode() ?: 400);
        }
        if ($ex instanceof PDOException && ($ex->errorInfo[1] ?? null) === 1062) {
            json_error('A record with the same name already exists.', 409);
        }
        if ($ex instanceof PDOException && in_array($ex->errorInfo[1] ?? null, [1451, 1452], true)) {
            json_error('This change conflicts with related records.', 409);
        }
        error_log((string) $ex);
        json_error('Unexpected server error.', 500);
    });

    start_session();
    $user = current_user();
    if (!$user) {
        json_error('Your session has expired. Please sign in again.', 401);
    }
    if (request_method() !== 'GET') {
        if ($writeRole === ROLE_ADMIN && $user['role'] !== ROLE_ADMIN) {
            json_error('You do not have permission to change data.', 403);
        }
        if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            json_error('Invalid or missing CSRF token. Reload the page and try again.', 419);
        }
    }
    // The session is only read from here on; release the lock so parallel requests are not serialized.
    session_write_close();
    return $user;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['error' => $message], $status);
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ApiException('Request body must be valid JSON.', 400);
    }
    return $data;
}

/** Read a positive integer from the query string (or null when absent). */
function query_id(string $name, bool $required = false): ?int
{
    $value = $_GET[$name] ?? null;
    if ($value === null || $value === '') {
        if ($required) {
            throw new ApiException("Missing parameter: $name", 400);
        }
        return null;
    }
    if (!ctype_digit((string) $value) || (int) $value <= 0) {
        throw new ApiException("Invalid parameter: $name", 400);
    }
    return (int) $value;
}

/** Comma separated list of positive integers from the query string, e.g. ?shift_ids=1,3 */
function query_id_list(string $name): array
{
    $raw = trim((string) ($_GET[$name] ?? ''));
    if ($raw === '') {
        return [];
    }
    return array_values(array_unique(array_map('intval', array_filter(explode(',', $raw), 'ctype_digit'))));
}

function require_text(array $data, string $field, string $label, int $maxLength = 150): string
{
    $value = trim((string) ($data[$field] ?? ''));
    if ($value === '') {
        throw new ApiException("$label is required.", 422);
    }
    if (mb_strlen($value) > $maxLength) {
        throw new ApiException("$label must be at most $maxLength characters.", 422);
    }
    return $value;
}

function require_int(array $data, string $field, string $label): int
{
    $value = $data[$field] ?? null;
    if (!is_numeric($value) || (int) $value <= 0) {
        throw new ApiException("$label is required.", 422);
    }
    return (int) $value;
}

function optional_number(array $data, string $field, string $label, float $min = 0, ?float $max = null): float
{
    $value = $data[$field] ?? null;
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (!is_numeric($value)) {
        throw new ApiException("$label must be a number.", 422);
    }
    $value = (float) $value;
    if ($value < $min || ($max !== null && $value > $max)) {
        $range = $max === null ? "at least $min" : "between $min and $max";
        throw new ApiException("$label must be $range.", 422);
    }
    return $value;
}

function optional_date(array $data, string $field, string $label): ?string
{
    $value = trim((string) ($data[$field] ?? ''));
    if ($value === '') {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new ApiException("$label must be a valid date (YYYY-MM-DD).", 422);
    }
    return $value;
}

function assert_exists(string $table, int $id, string $label): void
{
    $stmt = db()->prepare("SELECT 1 FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        throw new ApiException("$label not found.", 404);
    }
}
