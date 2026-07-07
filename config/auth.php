<?php

declare(strict_types=1);

/**
 * Admin (single-operator) authentication + geolocation settings.
 */
return [
    // The operator sign-in. Password is a bcrypt hash — generate with:
    //   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
    'admin' => [
        'email'         => env('ADMIN_EMAIL', ''),
        'password_hash' => env('ADMIN_PASSWORD_HASH', ''),
    ],

    // Free IP geolocation (ipwho.is). No key required.
    'geo' => [
        'enabled' => (bool) env('GEO_ENABLED', true),
        'endpoint' => env('GEO_ENDPOINT', 'https://ipwho.is/'),
    ],
];
