<?php

declare(strict_types=1);

return [
    'driver' => env('DB_DRIVER', 'sqlite'),

    'sqlite' => [
        'path' => base_path(env('DB_SQLITE_PATH', 'storage/database.sqlite')),
    ],

    'mysql' => [
        'host'     => env('DB_HOST', '127.0.0.1'),
        'port'     => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'brionic_reports'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
    ],
];
