<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

start_session();
if (current_user()) {
    header('Location: /app/index.php');
    exit;
}

$error = null;
$username = '';
$noAccounts = user_count() === 0;
if (request_method() === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $result = attempt_login($username, $password);
        if ($result === 'ok') {
            header('Location: /app/index.php');
            exit;
        }
        $error = $result === 'locked'
            ? 'Too many failed sign-in attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.'
            : 'Incorrect username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · <?= e(config('app_name')) ?></title>
    <link rel="stylesheet" href="/login.css">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
</head>
<body>
    <main class="login-container">
        <img src="/assets/img/logo.png" alt="<?= e(config('org_name')) ?>" class="logo">
        <h1><?= e(config('app_name')) ?> Panel</h1>
        <p class="org"><?= e(config('org_name')) ?></p>
        <?php if ($noAccounts): ?>
            <div class="notice">
                <strong>No accounts yet.</strong> Create the first administrator on the server:
                <code>docker compose exec web php bin/create-user.php admin</code>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <p class="error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
