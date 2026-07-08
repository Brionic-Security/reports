<?php

declare(strict_types=1);

/**
 * Traffic alert thresholds. Compares yesterday's human page views to the
 * average of the prior 7 days and emails the operator on a spike or drop.
 */
return [
    'enabled'      => (bool) env('ALERTS_ENABLED', true),
    // Alert when yesterday >= baseline * spike_factor.
    'spike_factor' => (float) env('ALERTS_SPIKE', 2.0),
    // Alert when yesterday <= baseline * drop_factor.
    'drop_factor'  => (float) env('ALERTS_DROP', 0.4),
    // Ignore sites whose baseline is below this (too small to be meaningful).
    'min_baseline' => (int) env('ALERTS_MIN_BASELINE', 20),
    // Recipient. Defaults to the operator (auth.admin.email) when empty.
    'to'           => env('ALERTS_EMAIL', ''),
];
