<?php
declare(strict_types=1);

// Create a user account from the command line.
//
//   php bin/create-user.php <username> [admin|viewer]
//   docker compose exec web php bin/create-user.php admin
//
// Prompts for the full name and password. For scripted setups, pass them in the
// NEW_USER_NAME and NEW_USER_PASSWORD environment variables instead.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

function prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    $tty = $hidden && stream_isatty(STDIN) && DIRECTORY_SEPARATOR === '/';
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $value;
}

$username = $argv[1] ?? '';
$role = $argv[2] ?? ROLE_ADMIN;
if ($username === '' || in_array($username, ['-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php bin/create-user.php <username> [admin|viewer]\n");
    exit(1);
}

try {
    validate_username($username);
    validate_role($role);
    $name = getenv('NEW_USER_NAME') ?: prompt('Full name: ');
    $password = getenv('NEW_USER_PASSWORD');
    if (!$password) {
        $password = prompt('Password (min ' . PASSWORD_MIN_LENGTH . ' characters): ', true);
        if (prompt('Repeat password: ', true) !== $password) {
            throw new ApiException('The passwords do not match.');
        }
    }
    $id = create_user($username, $name, $role, $password);
    fwrite(STDOUT, "Created $role account \"$username\" (id $id).\n");
} catch (ApiException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (PDOException $e) {
    fwrite(STDERR, ($e->errorInfo[1] ?? null) === 1062 ? "Error: the username \"$username\" is already taken.\n" : 'Database error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
