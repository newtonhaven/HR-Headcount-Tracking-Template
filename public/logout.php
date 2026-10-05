<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

start_session();
if (request_method() === 'POST' && verify_csrf($_POST['csrf_token'] ?? null)) {
    logout();
}
header('Location: /index.php');
exit;
