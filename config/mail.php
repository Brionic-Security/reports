<?php

declare(strict_types=1);

/**
 * Mail configuration.
 *   log  — write the rendered email to storage/logs/mail.log (dev / no SMTP).
 *   smtp — authenticated SMTP (recommended for production).
 */
return [
    'driver' => env('MAIL_DRIVER', 'log'),

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'reports@brionicsecurity.com'),
        'name'    => env('MAIL_FROM_NAME', config('app.name', 'Brionic Reports')),
    ],

    'support_email' => env('MAIL_SUPPORT_ADDRESS', 'support@brionicsecurity.com'),

    'smtp' => [
        'host'       => env('SMTP_HOST', ''),
        'port'       => (int) env('SMTP_PORT', 587),
        'username'   => env('SMTP_USERNAME', ''),
        'password'   => env('SMTP_PASSWORD', ''),
        'encryption' => env('SMTP_ENCRYPTION', 'tls'),
        'timeout'    => (int) env('SMTP_TIMEOUT', 15),
    ],
];
