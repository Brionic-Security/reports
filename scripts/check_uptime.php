<?php

declare(strict_types=1);

/**
 * Ping each monitored site and email the operator on a state change
 * (down or recovered). Response times / status are recorded for the dashboard.
 *
 *   php scripts/check_uptime.php                 # one pass
 *   php scripts/check_uptime.php --for=1740 --interval=300
 *
 * The --for/--interval loop lets one 30-minute cron slot poll every few
 * minutes for finer-grained monitoring. A file lock prevents overlap.
 *
 * Cron (every 30 min):
 *   0,30 * * * *  php /path/to/reports/scripts/check_uptime.php --for=1740 --interval=300 >> storage/logs/uptime.log 2>&1
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Services\UptimeService;

$opts = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$for = isset($opts['for']) ? max(0, (int) $opts['for']) : 0;
$interval = isset($opts['interval']) ? max(15, (int) $opts['interval']) : 300;

$lock = fopen(dirname(__DIR__) . '/storage/uptime.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "another uptime run is already active; exiting.\n";
    exit(0);
}

$runOnce = static function (): void {
    $results = UptimeService::run();
    echo gmdate('c') . ' — pinged ' . count($results) . " site(s). ";
    $changes = array_filter($results, fn ($r) => $r['changed']);
    echo $changes ? count($changes) . " state change(s):\n" : "no changes.\n";
    foreach ($results as $r) {
        echo sprintf("  %-26s %-4s HTTP %-3d %5dms%s\n",
            substr((string) $r['site'], 0, 26),
            $r['up'] ? 'UP' : 'DOWN',
            $r['status'], $r['ms'],
            $r['changed'] ? ($r['alerted'] ? '  [alerted]' : '  [changed]') : '');
    }
};

if ($for <= 0) {
    $runOnce();
    exit(0);
}

$deadline = time() + $for;
do {
    $start = time();
    $runOnce();
    $sleep = $interval - (time() - $start);
    if (time() + max(0, $sleep) >= $deadline) {
        break;
    }
    if ($sleep > 0) {
        sleep($sleep);
    }
} while (time() < $deadline);
