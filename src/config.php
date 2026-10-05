<?php

return [
    'app_name' => getenv('APP_NAME') ?: 'Norm Staffing',
    'org_name' => getenv('ORG_NAME') ?: 'Logoipsum Hospital',

    'db' => [
        'host' => getenv('DB_HOST') ?: 'db',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'db',
        'user' => getenv('DB_USER') ?: 'db',
        'pass' => getenv('DB_PASSWORD') ?: (getenv('DB_PASS') ?: 'db'),
    ],
];
