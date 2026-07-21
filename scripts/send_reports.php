<?php

declare(strict_types=1);

/**
 * Send weekly client traffic reports.
 *
 *   php scripts/send_reports.php                 # send to all sites (only fires on Fridays)
 *   php scripts/send_reports.php --days=30       # 30-day period instead of 7
 *   php scripts/send_reports.php --force         # ignore the day-of-week + "already sent" guards
 *   php scripts/send_reports.php --site=3        # only site id 3 (bypasses the Friday guard)
 *   php scripts/send_reports.php --day=1         # change the weekly send day (1=Mon .. 7=Sun)
 *   php scripts/send_reports.php --test=you@x.com --site=3   # preview-send to an address
 *
 * Cron (run hourly; the script only actually sends on the weekly report day and
 * dedupes so at most one report per site per week goes out):
 *   23 * * * *  php /path/to/reports/scripts/send_reports.php >> storage/logs/reports.log 2>&1
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Models\Site;
use App\Services\ReportService;

$opts = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$days = isset($opts['days']) ? max(1, (int) $opts['days']) : 7;
$force = isset($opts['force']);
$test = isset($opts['test']) && is_string($opts['test']) ? $opts['test'] : null;
$onlySite = isset($opts['site']) ? (int) $opts['site'] : null;

// Weekly cadence: the automated run only sends on the report day (default Friday, Pacific time).
// Explicit invocations (--force, --test, --site) always send regardless of day.
$reportDay = isset($opts['day']) ? max(1, min(7, (int) $opts['day'])) : 5; // ISO-8601: 1=Mon .. 5=Fri .. 7=Sun
$bypassSchedule = $force || $test !== null || $onlySite !== null;
$todayPt = new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles'));
if (!$bypassSchedule && (int) $todayPt->format('N') !== $reportDay) {
    $dayName = date('l', strtotime('Sunday +' . $reportDay . ' days'));
    echo "Skipping: weekly reports send on {$dayName} (today is " . $todayPt->format('l') . " PT). Use --force to override.\n";
    exit(0);
}

$period = ReportService::period($days);
echo 'Reporting period: ' . $period['label'] . " ({$period['from']} → {$period['to']})\n";

$sites = $onlySite !== null
    ? array_filter([Site::find($onlySite)])
    : Site::all();

if (!$sites) {
    echo "No matching sites.\n";
    exit(0);
}

$counts = ['sent' => 0, 'skipped' => 0, 'no_email' => 0, 'failed' => 0];
foreach ($sites as $site) {
    $result = ReportService::send($site, $days, $test, $force || $test !== null);
    $counts[$result] = ($counts[$result] ?? 0) + 1;
    $to = $test ?? ($site['report_email'] ?: '(none)');
    echo sprintf("  %-28s %-9s -> %s\n", $site['name'], $result, $to);
}

echo sprintf(
    "Done. sent=%d skipped=%d no_email=%d failed=%d\n",
    $counts['sent'], $counts['skipped'], $counts['no_email'], $counts['failed']
);
