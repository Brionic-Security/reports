<?php

declare(strict_types=1);

/**
 * Uptime monitoring. Each site is pinged on a cron; a state change (up→down or
 * down→up) emails the operator. Response time and status are recorded so the
 * dashboard can show an uptime percentage.
 */
return [
    'enabled'    => (bool) env('UPTIME_ENABLED', true),
    // Seconds before a request is considered failed.
    'timeout'    => (int) env('UPTIME_TIMEOUT', 12),
    // Recipient for downtime/recovery alerts. Defaults to the operator when empty.
    'to'         => env('UPTIME_EMAIL', ''),
    // How the monitor identifies itself.
    'user_agent' => env('UPTIME_UA', 'BrionicReportsUptime/1.0 (+https://reports.brionicsecurity.com)'),
];
