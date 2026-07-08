<?php

declare(strict_types=1);

/**
 * Check each site for a traffic spike or drop (yesterday vs the prior 7-day
 * average) and email the operator. De-duplicated per site/day/kind.
 *
 * Cron (daily, 08:00):
 *   0 8 * * *  php /path/to/reports/scripts/check_alerts.php >> storage/logs/alerts.log 2>&1
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Services\AlertService;

$alerts = AlertService::run();

echo gmdate('c') . ' — checked traffic alerts. ';
if (!$alerts) {
    echo "Nothing notable.\n";
    exit(0);
}
echo count($alerts) . " alert(s):\n";
foreach ($alerts as $a) {
    echo sprintf("  %-28s %-6s yesterday=%d baseline=%s sent=%s\n",
        $a['site'], $a['kind'], $a['yesterday'], $a['baseline'], $a['sent'] ? 'yes' : 'no');
}
