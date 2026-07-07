<?php

declare(strict_types=1);

return [
    'name'     => env('APP_NAME', 'Brionic Reports'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => rtrim((string) env('APP_URL', 'http://127.0.0.1:8790'), '/'),
    'key'      => env('APP_KEY', ''),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
];
